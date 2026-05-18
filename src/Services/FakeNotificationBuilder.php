<?php

namespace StructureManager\Services;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;

/**
 * Builds CCP-shaped notification payloads for the Test Notification Lab.
 *
 * The dispatch path is:
 *   1. Caller picks a target test structure + a notification type
 *   2. This service produces a row equivalent to what SeAT would write into
 *      `character_notifications` after fetching it from ESI
 *   3. The InjectTestNotification command writes the row
 *   4. Structure Manager's process-notifications job picks it up on the
 *      next 1-minute tick, dedups against the local table, and dispatches
 *      via StructureEventHandler exactly as if CCP had sent it
 *
 * The YAML field names mirror CCP's actual payloads — see SM's
 * StructureEventHandler::resolveStructureMeta and family-specific builders
 * (buildAttackPayload / buildLifecyclePayload / buildFuelEventPayload /
 * buildSovereigntyPayload / buildServicesOfflinePayload) for the consumer
 * side. Field-name quirks reproduced faithfully:
 *
 *   - Upwell family uses `solarsystemID` (lowercase 's')
 *   - Sov family uses `solarSystemID` (capital S)
 *   - Both use `structureID` / `structureShowInfoData`
 *   - timeLeft / vulnerableTime / decloakTime are 100-NANOSECOND TICKS
 *     (Windows FILETIME / .NET TimeSpan convention — 1 sec = 10,000,000 ticks).
 *     SM's formatCcpDuration() divides by 10_000_000 when reading, so we
 *     multiply seconds by 10_000_000 when building.
 *
 * Output is keyed for direct insertion into `character_notifications`:
 *   ['type' => ..., 'sender_id' => ..., 'sender_type' => ..., 'text' => yaml-string]
 */
class FakeNotificationBuilder
{
    /**
     * Every notification type the Test Lab can inject.
     * Grouped by family for the UI; the array order is the UI button order.
     *
     * Each entry: slug => [label, family]
     */
    public const SUPPORTED_TYPES = [
        // Attack family (high-urgency)
        'StructureUnderAttack'    => ['label' => 'Structure Under Attack',      'family' => 'attack'],
        'StructureLostShields'    => ['label' => 'Shields Down',                'family' => 'attack'],
        'StructureLostArmor'      => ['label' => 'Armor Down',                  'family' => 'attack'],
        'StructureDestroyed'      => ['label' => 'Structure Destroyed',         'family' => 'attack'],
        'SkyhookUnderAttack'      => ['label' => 'Skyhook Under Attack',        'family' => 'attack'],
        'SkyhookLostShields'      => ['label' => 'Skyhook Shields Down',        'family' => 'attack'],
        'SkyhookDestroyed'        => ['label' => 'Skyhook Destroyed',           'family' => 'attack'],

        // Lifecycle
        'StructureAnchoring'      => ['label' => 'Anchoring Started',           'family' => 'lifecycle'],
        'AllAnchoringMsg'         => ['label' => 'Anchoring Detected (system)', 'family' => 'lifecycle'],
        'StructureUnanchoring'    => ['label' => 'Unanchoring Started',         'family' => 'lifecycle'],
        'OwnershipTransferred'    => ['label' => 'Ownership Transferred',       'family' => 'lifecycle'],
        'SkyhookDeployed'         => ['label' => 'Skyhook Deployed',            'family' => 'lifecycle'],

        // Fuel + power
        'StructureWentLowPower'   => ['label' => 'Went Low Power',              'family' => 'fuel'],
        'StructureWentHighPower'  => ['label' => 'High Power Restored',         'family' => 'fuel'],
        'StructureFuelAlert'      => ['label' => 'Fuel Alert (CCP)',            'family' => 'fuel'],
        'StructureLowReagentsAlert' => ['label' => 'Low Reagents (Metenox)',    'family' => 'fuel'],
        'StructureNoReagentsAlert'  => ['label' => 'No Reagents (Metenox)',     'family' => 'fuel'],
        'SkyhookOnline'           => ['label' => 'Skyhook Online',              'family' => 'fuel'],

        // Services
        'StructureServicesOffline' => ['label' => 'Services Offline',           'family' => 'services'],

        // Sovereignty
        'EntosisCaptureStarted'      => ['label' => 'Entosis Capture Started', 'family' => 'sov'],
        'SovStructureReinforced'     => ['label' => 'Sov Structure Reinforced', 'family' => 'sov'],
        'SovStructureDestroyed'      => ['label' => 'Sov Structure Destroyed',  'family' => 'sov'],
        'SovCommandNodeEventStarted' => ['label' => 'Command Node Event',       'family' => 'sov'],
    ];

    // Default attacker context — caller can override via $params
    private const DEFAULT_ATTACKER_CHAR_ID  = 95000001; // safely in real-EVE range
    private const DEFAULT_ATTACKER_CORP_ID  = 98000001;
    private const DEFAULT_ATTACKER_CORP_NAME = 'Test Aggressor Corp';
    private const DEFAULT_ATTACKER_ALLIANCE_ID   = null;
    private const DEFAULT_ATTACKER_ALLIANCE_NAME = null;

    /**
     * Build a complete `character_notifications`-ready row.
     *
     * @param string $type CCP notification type (must be in SUPPORTED_TYPES)
     * @param int    $structureId Target structure (must be a known test structure)
     * @param array  $params Optional overrides:
     *   attacker_character_id, attacker_corp_id, attacker_corp_name,
     *   attacker_alliance_id, attacker_alliance_name, time_left_seconds
     *
     * @return array{type:string, sender_id:int, sender_type:string, text:string}
     * @throws \InvalidArgumentException
     */
    public function build(string $type, int $structureId, array $params = []): array
    {
        if (!isset(self::SUPPORTED_TYPES[$type])) {
            throw new \InvalidArgumentException("Unknown notification type: {$type}");
        }

        // Resolve structure metadata from corporation_structures + universe_structures.
        // The structure MUST exist before we build — otherwise the dispatched
        // webhook would reference an unknown structure and the structure-meta
        // resolver would render "Unknown".
        $structure = $this->resolveStructure($structureId);
        if ($structure === null) {
            throw new \InvalidArgumentException(
                "Structure #{$structureId} not found in corporation_structures. "
                . 'Run create-test-upwell-structures first.'
            );
        }

        $family = self::SUPPORTED_TYPES[$type]['family'];

        $data = match ($family) {
            'attack'    => $this->buildAttackData($type, $structure, $params),
            'lifecycle' => $this->buildLifecycleData($type, $structure, $params),
            'fuel'      => $this->buildFuelData($type, $structure, $params),
            'services'  => $this->buildServicesData($type, $structure, $params),
            'sov'       => $this->buildSovData($type, $structure, $params),
        };

        // sender info: for attack-style notifications the sender is the attacking
        // corp/alliance; for non-attack notifications, the sender is the structure's
        // owning corp (CCP convention: it's a "system" notification then).
        [$senderId, $senderType] = $this->resolveSender($family, $structure, $params, $type);

        return [
            'type'        => $type,
            'sender_id'   => $senderId,
            'sender_type' => $senderType,
            'text'        => Yaml::dump($data, 4, 2, Yaml::DUMP_NUMERIC_KEY_AS_STRING),
        ];
    }

    /**
     * Family: attack
     *
     * Per-type field shapes verified against SeAT core's Discord templates:
     *
     *   StructureUnderAttack: solarsystemID, structureID, structureShowInfoData,
     *     corpLinkData[2]=corpID, corpName, aggressorAllianceID (gate),
     *     allianceID, allianceName, charID,
     *     shieldPercentage, armorPercentage, hullPercentage
     *
     *   StructureLostShields/LostArmor: solarsystemID, structureTypeID (FLAT),
     *     attacker block, timeLeft (100-ns ticks duration)
     *
     *   StructureDestroyed: solarsystemID, structureTypeID (FLAT),
     *     ownerCorpName, ownerCorpLinkData[2]=ownerCorpID
     *     (NO attacker block — kill data goes via killmail/zKB)
     *
     *   Skyhook* family: see buildSkyhookData
     */
    private function buildAttackData(string $type, array $structure, array $params): array
    {
        // Skyhook attacks have a different shape — handled separately
        if (str_starts_with($type, 'Skyhook')) {
            return $this->buildSkyhookData($type, $structure, $params);
        }

        $data = $this->commonStructureFields($structure);

        // Also emit the FLAT structureTypeID alongside structureShowInfoData
        // — SeAT's templates for LostShields/LostArmor/Destroyed/Anchoring/
        // Unanchoring/WentLowPower read the flat field, not structureShowInfoData.
        // Including both makes the fake YAML compatible with both reading paths.
        $data['structureTypeID'] = (int) $structure['type_id'];

        // StructureDestroyed is SEMANTICALLY the lost-owner notification — CCP
        // does NOT include attacker info (that goes via killmail). Surface
        // ownerCorpName + ownerCorpLinkData instead.
        if ($type === 'StructureDestroyed') {
            $data['ownerCorpName']     = "Test Owner Corp";
            $data['ownerCorpLinkData'] = [2, 0, (int) $structure['corporation_id']];
            return $data;
        }

        // Attack-progression notifications: attacker context.
        $attackerCharId = (int) ($params['attacker_character_id'] ?? self::DEFAULT_ATTACKER_CHAR_ID);
        $attackerCorpId = (int) ($params['attacker_corp_id']      ?? self::DEFAULT_ATTACKER_CORP_ID);
        $attackerCorpName = (string) ($params['attacker_corp_name'] ?? self::DEFAULT_ATTACKER_CORP_NAME);

        $data['charID']   = $attackerCharId;
        $data['corpID']   = $attackerCorpId;
        $data['corpName'] = $attackerCorpName;

        // SeAT's StructureUnderAttack template specifically reads
        // corpLinkData[2] for the attacker corp ID. Emit it (3-element array,
        // [showInfoType=2, 0, corpID]) so SeAT-style consumers also work.
        $data['corpLinkData'] = [2, 0, $attackerCorpId];

        $allianceId   = $params['attacker_alliance_id']   ?? self::DEFAULT_ATTACKER_ALLIANCE_ID;
        $allianceName = $params['attacker_alliance_name'] ?? self::DEFAULT_ATTACKER_ALLIANCE_NAME;
        if ($allianceId !== null) {
            $data['allianceID']          = (int) $allianceId;
            $data['allianceName']        = (string) ($allianceName ?? 'Test Alliance');
            // SeAT gates attacker-alliance display on aggressorAllianceID
            // presence (this is the "is this alliance combat" flag CCP sets
            // when the attacker IS in an alliance).
            $data['aggressorAllianceID'] = (int) $allianceId;
        }

        // Per-type extras
        switch ($type) {
            case 'StructureUnderAttack':
                // Mid-fight HP percentages
                $data['shieldPercentage'] = 67.5;
                $data['armorPercentage']  = 100.0;
                $data['hullPercentage']   = 100.0;
                break;

            case 'StructureLostShields':
                // Armor reinforce timer (24h default — CCP encodes 100-ns ticks)
                $data['timeLeft'] = $this->seconds($params['time_left_seconds'] ?? 24 * 3600);
                break;

            case 'StructureLostArmor':
                // Hull reinforce timer (default 48h for nullsec, 24h hi/lo)
                $data['timeLeft'] = $this->seconds($params['time_left_seconds'] ?? 48 * 3600);
                break;
        }

        return $data;
    }

    /**
     * Skyhook events have a different shape than Upwell structures:
     * skyhooks anchor on PLANETS (not in space), so notifications carry
     * planetID + skyhookID rather than structureID + structureShowInfoData.
     *
     * SeAT v5 has no reference templates for skyhooks (they post-date its
     * notification module). The shape here is a best-effort reconstruction
     * based on CCP's notification conventions. Marked UNVERIFIED — when a
     * real SkyhookDeployed lands in production, cross-check the fields.
     *
     * Field shape:
     *   solarsystemID  (lowercase, Upwell-derived convention)
     *   skyhookID      (instance ID — placeholder sentinel for tests)
     *   planetID       (resolved from mapDenormalize for the picked system)
     *   typeID         (skyhook type — placeholder, real type IDs vary)
     *   For attack family: charID, corpID, corpName, alliance fields, percentages
     *   For destroyed: ownerCorpName, ownerCorpLinkData
     *   For SkyhookDeployed: timeLeft (100-ns ticks duration)
     */
    private function buildSkyhookData(string $type, array $structure, array $params): array
    {
        // Skyhook anchors on a planet IN the picked structure's system — so
        // the test event still appears "near" the picked Upwell. Resolve the
        // first planet in that system from mapDenormalize, fall back to the
        // system ID itself if no planets are in the SDE.
        $planetId = DB::table('mapDenormalize')
            ->where('solarSystemID', $structure['system_id'])
            ->where('groupID', 7) // Planet group
            ->value('itemID');
        if (empty($planetId)) {
            $planetId = (int) $structure['system_id']; // fallback — embed will show system as planet
        }

        // Test sentinel skyhook ID — well outside CCP's allocation, can be
        // distinguished from real skyhooks for cleanup. We don't have a
        // dedicated SKYHOOK_ID range yet; reuse the structure_id + 7000000
        // offset to keep test sentinels deterministic and in the safe range.
        $skyhookId = (int) $structure['structure_id'] + 7000000000;

        $data = [
            'solarsystemID' => (int) $structure['system_id'],
            'skyhookID'     => $skyhookId,
            'planetID'      => (int) $planetId,
            // Real Skyhook type IDs vary — using a sentinel typeID. Real
            // installations would see whatever typeID CCP includes (likely
            // an Orbital Skyhook variant in the 81000+ range).
            'typeID'        => 81080,
        ];

        $isAttackFamily = in_array($type, [
            'SkyhookUnderAttack',
            'SkyhookLostShields',
            'SkyhookDestroyed',
        ], true);

        if ($type === 'SkyhookDestroyed') {
            // Destroyed = lost owner, not attacker (matches Upwell convention)
            $data['ownerCorpName']     = 'Test Owner Corp';
            $data['ownerCorpLinkData'] = [2, 0, (int) $structure['corporation_id']];
        } elseif ($isAttackFamily) {
            // SkyhookUnderAttack / SkyhookLostShields — attacker context
            $attackerCharId   = (int) ($params['attacker_character_id'] ?? self::DEFAULT_ATTACKER_CHAR_ID);
            $attackerCorpId   = (int) ($params['attacker_corp_id']      ?? self::DEFAULT_ATTACKER_CORP_ID);
            $attackerCorpName = (string) ($params['attacker_corp_name'] ?? self::DEFAULT_ATTACKER_CORP_NAME);

            $data['charID']       = $attackerCharId;
            $data['corpID']       = $attackerCorpId;
            $data['corpName']     = $attackerCorpName;
            $data['corpLinkData'] = [2, 0, $attackerCorpId];

            $allianceId   = $params['attacker_alliance_id']   ?? self::DEFAULT_ATTACKER_ALLIANCE_ID;
            $allianceName = $params['attacker_alliance_name'] ?? self::DEFAULT_ATTACKER_ALLIANCE_NAME;
            if ($allianceId !== null) {
                $data['allianceID']          = (int) $allianceId;
                $data['allianceName']        = (string) ($allianceName ?? 'Test Alliance');
                $data['aggressorAllianceID'] = (int) $allianceId;
            }

            if ($type === 'SkyhookUnderAttack') {
                $data['shieldPercentage'] = 67.5;
                $data['armorPercentage']  = 100.0;
                $data['hullPercentage']   = 100.0;
            } elseif ($type === 'SkyhookLostShields') {
                $data['timeLeft'] = $this->seconds($params['time_left_seconds'] ?? 24 * 3600);
            }
        } elseif ($type === 'SkyhookDeployed') {
            $data['timeLeft']      = $this->seconds($params['time_left_seconds'] ?? 24 * 3600);
            $data['ownerCorpName'] = 'Test Owner Corp';
        }
        // SkyhookOnline: no extra fields beyond the common skyhook shape

        return $data;
    }

    /**
     * Family: lifecycle
     *
     * Per-type shapes verified against SeAT core's Discord templates:
     *
     *   StructureAnchoring: solarsystemID, structureID, structureTypeID (FLAT),
     *     ownerCorpName, ownerCorpLinkData[2], timeLeft, vulnerableTime
     *
     *   AllAnchoringMsg: SOLARSYSTEMID (capital S), moonID, corpID, corpName,
     *     typeID (flat — NOT structureTypeID, NOT structureShowInfoData),
     *     timeLeft.
     *     This is a SYSTEM-WIDE warning (a hostile is anchoring something),
     *     not an owner-side notification — different field convention.
     *
     *   StructureUnanchoring: solarsystemID, structureID, structureTypeID,
     *     ownerCorpName, ownerCorpLinkData[2], timeLeft
     *
     *   SkyhookDeployed: see buildSkyhookData (planet-anchored, different shape)
     *
     *   OwnershipTransferred: see dedicated case below — sov-style fields
     */
    private function buildLifecycleData(string $type, array $structure, array $params): array
    {
        // SkyhookDeployed has a different shape (planet-anchored, not structure)
        if ($type === 'SkyhookDeployed') {
            return $this->buildSkyhookData($type, $structure, $params);
        }

        // AllAnchoringMsg uses sov-style fields — full rewrite, not commonStructureFields
        if ($type === 'AllAnchoringMsg') {
            // Resolve a moon in the picked structure's system from mapDenormalize
            // so the embed has a realistic location reference. Group ID 8 = Moon.
            $moonId = DB::table('mapDenormalize')
                ->where('solarSystemID', $structure['system_id'])
                ->where('groupID', 8)
                ->value('itemID');
            if (empty($moonId)) {
                // No moons in the system (possible for some null-sec test cases) —
                // fall back to system_id. Embed will skip the Moon field then.
                $moonId = (int) $structure['system_id'];
            }

            return [
                // System-wide warning fields (sov-style capital S convention)
                'solarSystemID' => (int) $structure['system_id'],
                'moonID'        => (int) $moonId,
                'corpID'        => (int) ($params['attacker_corp_id'] ?? self::DEFAULT_ATTACKER_CORP_ID),
                'corpName'      => (string) ($params['attacker_corp_name'] ?? 'Hostile Anchoring Corp'),
                // Flat typeID — what's being anchored. Default to Astrahus
                // (most common citadel) so the warning has a realistic context.
                'typeID'        => 35832,
                // Anchoring countdown (24h default; 100-ns ticks)
                'timeLeft'      => $this->seconds($params['time_left_seconds'] ?? 24 * 3600),
            ];
        }

        $data = $this->commonStructureFields($structure);
        // Flat structureTypeID alongside structureShowInfoData — SeAT's
        // templates for Anchoring/Unanchoring read the flat field.
        $data['structureTypeID'] = (int) $structure['type_id'];

        switch ($type) {
            case 'StructureAnchoring':
                $data['timeLeft']          = $this->seconds($params['time_left_seconds'] ?? 24 * 3600);
                $data['vulnerableTime']    = $this->seconds(15 * 60);
                $data['ownerCorpName']     = 'Test Owner Corp';
                $data['ownerCorpLinkData'] = [2, 0, (int) $structure['corporation_id']]; // 3-element form
                break;

            case 'StructureUnanchoring':
                $data['timeLeft']          = $this->seconds($params['time_left_seconds'] ?? 7 * 24 * 3600);
                $data['ownerCorpName']     = 'Test Owner Corp';
                $data['ownerCorpLinkData'] = [2, 0, (int) $structure['corporation_id']];
                break;

            case 'OwnershipTransferred':
                // CCP's OwnershipTransferred YAML uses DIFFERENT field names
                // than the rest of the lifecycle family — it matches the sov
                // family's capital-S convention. Reset $data and rebuild
                // from scratch using CCP's actual shape (see SeAT core's
                // OwnershipTransferred Discord notification template):
                //
                //   solarSystemID    (capital S in middle, NOT solarsystemID)
                //   structureID
                //   structureTypeID  (NOT structureShowInfoData)
                //   structureName    (carried directly, no universe_structures lookup)
                //   oldOwnerCorpID
                //   newOwnerCorpID
                //   charID           (the character that initiated the transfer)
                //
                // CCP does NOT include corp/character names — receivers resolve
                // them from local caches (corporation_infos, universe_names,
                // character_infos). Don't synthesize names here.
                // Default new owner = the "secondary" test corp created by
                // CreateTestUpwellStructuresCommand (CORP_ID_MIN + 1) so the
                // embed renders a known corp name. Falls back to a real-EVE
                // range ID if no test data was generated yet.
                $defaultNewOwner = \StructureManager\Services\TestDataGenerator::isTestCorp(
                    (int) $structure['corporation_id']
                )
                    ? \StructureManager\Services\TestDataGenerator::CORP_ID_MIN + 1
                    : 98000099;

                $data = [
                    'solarSystemID'   => (int) $structure['system_id'],
                    'structureID'     => (int) $structure['structure_id'],
                    'structureTypeID' => (int) $structure['type_id'],
                    'structureName'   => $structure['name'] ?? ('TEST - Structure #' . $structure['structure_id']),
                    'oldOwnerCorpID'  => (int) $structure['corporation_id'],
                    'newOwnerCorpID'  => (int) ($params['new_owner_corp_id'] ?? $defaultNewOwner),
                    'charID'          => (int) ($params['attacker_character_id'] ?? self::DEFAULT_ATTACKER_CHAR_ID),
                ];
                break;
        }

        return $data;
    }

    /**
     * Family: fuel + power
     *
     * Per-type shapes (verified against SeAT templates where present):
     *
     *   StructureWentLowPower: solarsystemID, structureID, structureTypeID (FLAT)
     *   StructureWentHighPower: solarsystemID, structureID, structureShowInfoData
     *   StructureFuelAlert: solarsystemID, structureID, structureShowInfoData,
     *     listOfTypesAndQty
     *   StructureLowReagentsAlert / StructureNoReagentsAlert: UNVERIFIED
     *     (post-Equinox, no SeAT reference template). Best-effort field shape.
     *   SkyhookOnline: see buildSkyhookData
     */
    private function buildFuelData(string $type, array $structure, array $params): array
    {
        if ($type === 'SkyhookOnline') {
            return $this->buildSkyhookData($type, $structure, $params);
        }

        $data = $this->commonStructureFields($structure);
        // Flat structureTypeID for SeAT-style readers (StructureWentLowPower
        // explicitly reads the flat field). Including both is harmless and
        // makes the fake compatible with any handler.
        $data['structureTypeID'] = (int) $structure['type_id'];

        switch ($type) {
            case 'StructureFuelAlert':
                // Remaining fuel — list of [quantity, typeID] pairs.
                // 4051 = Nitrogen Fuel Block (the most common citadel fuel)
                $data['listOfTypesAndQty'] = [[1200, 4051]];
                break;

            case 'StructureLowReagentsAlert':
            case 'StructureNoReagentsAlert':
                // Metenox magmatic gas — CCP YAML format: [[qty, typeID]]
                // typeID 81143 = Magmatic Gas
                $data['listOfTypesAndQty'] = [[($type === 'StructureNoReagentsAlert') ? 0 : 5000, 81143]];
                break;

            case 'StructureWentLowPower':
            case 'StructureWentHighPower':
            case 'SkyhookOnline':
                // Just structure context — SM looks up fuel_expires from
                // corporation_structures itself
                break;
        }

        return $data;
    }

    /**
     * Family: services
     *
     * StructureServicesOffline carries listOfServiceModuleIDs —
     * an array of typeIDs of the offline service modules.
     *
     * Test default: a manufacturing module (typeID 35878 = Standup Manufacturing
     * Plant I) — categorizes as "industry / critical impact" in SM's enriched embed.
     */
    private function buildServicesData(string $type, array $structure, array $params): array
    {
        $data = $this->commonStructureFields($structure);

        // 35878 = Standup Manufacturing Plant I (industry)
        // 35892 = Standup Market Hub I (market)
        // 35923 = Standup Cloning Center I (cloning)
        $defaultModules = $params['service_module_ids'] ?? [35878, 35892];
        $data['listOfServiceModuleIDs'] = array_map('intval', (array) $defaultModules);

        return $data;
    }

    /**
     * Family: sovereignty
     *
     * Sov notifications are SEMANTICALLY DIFFERENT from Upwell:
     *   - They reference TCU / IHUB / Outpost (the sov claim structures),
     *     NOT the picked test Upwell structure.
     *   - They do NOT carry a structure_id (no specific instance, just system + type).
     *   - They use `solarSystemID` (capital S) like OwnershipTransferred.
     *
     * Per-notification fields (matches SeAT core's sov Discord templates):
     *
     *   EntosisCaptureStarted     solarSystemID, structureTypeID
     *   SovStructureDestroyed     solarSystemID, structureTypeID
     *   SovStructureReinforced    solarSystemID, campaignEventType, decloakTime
     *   SovCommandNodeEventStarted solarSystemID, campaignEventType
     *
     * Key gotcha: `decloakTime` is Microsoft FILETIME (absolute time in 100-ns
     * ticks since 1601-01-01 UTC), NOT a duration. SeAT's NotificationTools
     * trait uses `(filetime / 10M) - 11644473600` to convert to unix seconds.
     * Don't use the `seconds()` helper here — that's for durations.
     *
     * Real sov structure type IDs:
     *   32226 = Territorial Claim Unit (TCU)
     *   32458 = Infrastructure Hub
     * Real campaign event types:
     *   1 = TCU defense
     *   2 = IHUB defense
     *   3 = Outpost / Station freeport (mostly obsolete post-2018 outpost removal)
     */
    private function buildSovData(string $type, array $structure, array $params): array
    {
        // Sov defaults to TCU. The picked test structure is irrelevant for sov
        // semantics — we only use its system_id (since the structure does live
        // in some system). The sov structure type is the actual TCU type ID.
        $sovTypeId = (int) ($params['sov_structure_type_id'] ?? 32226); // TCU
        $sovCampaignType = (int) ($params['campaign_event_type'] ?? 1); // 1=TCU defense

        $data = [
            'solarSystemID' => (int) $structure['system_id'],
        ];

        switch ($type) {
            case 'EntosisCaptureStarted':
                $data['structureTypeID'] = $sovTypeId;
                $data['charID']          = (int) ($params['attacker_character_id'] ?? self::DEFAULT_ATTACKER_CHAR_ID);
                $data['corpID']          = (int) ($params['attacker_corp_id'] ?? self::DEFAULT_ATTACKER_CORP_ID);
                break;

            case 'SovStructureDestroyed':
                $data['structureTypeID'] = $sovTypeId;
                break;

            case 'SovStructureReinforced':
                // decloakTime = Microsoft FILETIME absolute timestamp.
                // We want it ~30h in the future by default (matches typical
                // sov reinforce timer). Convert: (unix_seconds + 11644473600) * 10M.
                $targetUnixSeconds = time() + (int) ($params['time_left_seconds'] ?? 30 * 3600);
                $data['decloakTime'] = ($targetUnixSeconds + 11644473600) * 10_000_000;
                $data['campaignEventType'] = $sovCampaignType;
                break;

            case 'SovCommandNodeEventStarted':
                $data['campaignEventType'] = $sovCampaignType;
                break;
        }

        return $data;
    }

    /**
     * Common Upwell-family fields (everything except sov).
     */
    private function commonStructureFields(array $structure): array
    {
        return [
            'structureID'           => (int) $structure['structure_id'],
            // [showInfoType=10, structure_type_id, structure_id]
            'structureShowInfoData' => [10, (int) $structure['type_id'], (int) $structure['structure_id']],
            'solarsystemID'         => (int) $structure['system_id'],
        ];
    }

    /**
     * Resolve test structure metadata from corporation_structures + universe_structures.
     *
     * @return array{structure_id:int, corporation_id:int, system_id:int, type_id:int, name:?string}|null
     */
    private function resolveStructure(int $structureId): ?array
    {
        $row = DB::table('corporation_structures')
            ->where('structure_id', $structureId)
            ->select('structure_id', 'corporation_id', 'system_id', 'type_id')
            ->first();

        if (!$row) {
            return null;
        }

        $name = DB::table('universe_structures')
            ->where('structure_id', $structureId)
            ->value('name');

        return [
            'structure_id'   => (int) $row->structure_id,
            'corporation_id' => (int) $row->corporation_id,
            'system_id'      => (int) $row->system_id,
            'type_id'        => (int) $row->type_id,
            'name'           => $name,
        ];
    }

    /**
     * Resolve sender_id / sender_type for the character_notifications row.
     *
     * Mirrors what CCP's notification envelope would carry:
     *   attack family   -> attacker corp
     *   sov family      -> attacker corp (the one entosising)
     *   AllAnchoringMsg -> hostile corp (the one doing the anchoring,
     *                       since it's a system-wide warning, not owner-side)
     *   everything else -> structure's owning corp (operator-side notification)
     *
     * @return array{0:int, 1:string}
     */
    private function resolveSender(string $family, array $structure, array $params, string $type = ''): array
    {
        if ($family === 'attack' || $family === 'sov' || $type === 'AllAnchoringMsg') {
            // Hostile/attacker corp is the sender
            $corpId = (int) ($params['attacker_corp_id'] ?? self::DEFAULT_ATTACKER_CORP_ID);
            return [$corpId, 'corporation'];
        }

        // Lifecycle / fuel / services come "from" the structure's owning corp
        return [(int) $structure['corporation_id'], 'corporation'];
    }

    /**
     * CCP times durations in 100-nanosecond ticks (Windows FILETIME convention),
     * NOT plain nanoseconds. Convert seconds to those ticks.
     *
     * 1 second = 10,000,000 ticks.
     */
    private function seconds(int $seconds): int
    {
        return $seconds * 10_000_000;
    }
}
