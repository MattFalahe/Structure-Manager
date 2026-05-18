<?php

namespace StructureManager\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use StructureManager\Models\NotificationCategory;
use StructureManager\Models\WebhookConfiguration;
use StructureManager\Services\DiscordRoleResolver;

/**
 * Notifications settings page controller.
 *
 * Split off from SettingsController. Handles:
 *  - Category master toggles + default role mention
 *  - Per-category binding to webhooks (via the category_webhook pivot)
 *  - Per-binding role mention overrides
 *  - AJAX role list for the Discord connector dropdown
 *
 * Webhook CRUD (add/edit/delete webhook rows) remains in SettingsController
 * to avoid breaking existing routes. This controller only manages the
 * category <-> webhook relationships.
 */
class NotificationController extends Controller
{
    /**
     * Display the Notifications settings page.
     */
    public function index()
    {
        $categories = NotificationCategory::orderBy('namespace')
            ->orderBy('sort_order')
            ->orderBy('display_name')
            ->get()
            ->groupBy('namespace');

        $webhooks = WebhookConfiguration::orderBy('description')
            ->orderBy('id')
            ->get();

        // Map category_id -> array of webhook_ids bound (for pre-checking the multi-select)
        $bindings = DB::table('structure_manager_category_webhook')
            ->get()
            ->groupBy('category_id');

        // Role provider detection (may return multiple — we union them)
        $roleProviders          = DiscordRoleResolver::detectAvailableProviders();
        $roleProviderLabel      = DiscordRoleResolver::providerLabel();
        $roleProviderAvailable  = !empty($roleProviders);
        $roleProvider           = $roleProviders[0] ?? null; // primary for legacy checks

        // Namespace display metadata (order + labels + legacy hint)
        $namespaces = [
            'upwell' => [
                'label' => 'Upwell Structures',
                'legacy' => false,
                'description' => 'Citadels, engineering complexes, refineries, Metenox moon drills — notifications driven by periodic fuel-bay polling.',
            ],
            'events' => [
                'label' => 'Structure Events (ESI Notifications)',
                'legacy' => false,
                'description' => 'Attack alerts, anchoring, ownership transfers — driven by EVE\'s notification stream via Manager Core fast-poll (or SeAT native if MC absent).',
            ],
            'pos' => [
                'label' => 'POS (Legacy Starbases)',
                'legacy' => true,
                'description' => 'CCP legacy structures. May be removed by CCP in a future patch — kept isolated for clean removal.',
            ],
        ];

        return view('structure-manager::notifications.index', [
            'categories'            => $categories,
            'webhooks'              => $webhooks,
            'bindings'              => $bindings,
            'namespaces'            => $namespaces,
            'roleProvider'          => $roleProvider,
            'roleProviders'         => $roleProviders,
            'roleProviderLabel'     => $roleProviderLabel,
            'roleProviderAvailable' => $roleProviderAvailable,
        ]);
    }

    /**
     * POST /settings/notifications/category/{id}
     * Update a category's enabled + default role mention.
     */
    public function updateCategory(Request $request, int $id)
    {
        $category = NotificationCategory::findOrFail($id);

        $request->validate([
            'enabled'      => 'nullable',
            'role_mention' => 'nullable|string|max:100',
            'role_source'  => 'nullable|string|in:manual,seat-connector,warlof-discord',
            'role_id'      => 'nullable|string|max:32',
        ]);

        $category->enabled = $request->boolean('enabled');
        $category->role_mention = $request->input('role_mention') ?: null;
        $category->role_source  = $request->input('role_source') ?: ($category->role_mention ? 'manual' : null);
        $category->role_id      = $request->input('role_id') ?: null;
        $category->save();

        return response()->json(['success' => true, 'category' => $category->fresh()]);
    }

    /**
     * POST /settings/notifications/category/{categoryId}/bind/{webhookId}
     * Attach a webhook to a category (creates pivot row) or update existing binding.
     */
    public function upsertBinding(Request $request, int $categoryId, int $webhookId)
    {
        $category = NotificationCategory::findOrFail($categoryId);
        $webhook  = WebhookConfiguration::findOrFail($webhookId);

        $request->validate([
            'enabled'      => 'nullable',
            'role_mention' => 'nullable|string|max:100',
            'role_source'  => 'nullable|string|in:manual,seat-connector,warlof-discord',
            'role_id'      => 'nullable|string|max:32',
        ]);

        $existing = DB::table('structure_manager_category_webhook')
            ->where('category_id', $categoryId)
            ->where('webhook_id', $webhookId)
            ->first();

        $attrs = [
            'enabled'      => $request->has('enabled') ? $request->boolean('enabled') : true,
            'role_mention' => $request->input('role_mention') ?: null,
            'role_source'  => $request->input('role_source') ?: null,
            'role_id'      => $request->input('role_id') ?: null,
            'updated_at'   => now(),
        ];

        if ($existing) {
            DB::table('structure_manager_category_webhook')
                ->where('id', $existing->id)
                ->update($attrs);
        } else {
            DB::table('structure_manager_category_webhook')->insert(array_merge($attrs, [
                'category_id' => $categoryId,
                'webhook_id'  => $webhookId,
                'created_at'  => now(),
            ]));
        }

        return response()->json(['success' => true]);
    }

    /**
     * DELETE /settings/notifications/category/{categoryId}/bind/{webhookId}
     * Remove the binding (unbind the webhook from this category).
     */
    public function removeBinding(int $categoryId, int $webhookId)
    {
        DB::table('structure_manager_category_webhook')
            ->where('category_id', $categoryId)
            ->where('webhook_id', $webhookId)
            ->delete();

        return response()->json(['success' => true]);
    }

    /**
     * POST /settings/notifications/category/{categoryId}/bind/{webhookId}/toggle
     * Quick enable/disable toggle without deleting the binding.
     */
    public function toggleBinding(int $categoryId, int $webhookId)
    {
        $row = DB::table('structure_manager_category_webhook')
            ->where('category_id', $categoryId)
            ->where('webhook_id', $webhookId)
            ->first();

        if (!$row) {
            return response()->json(['error' => 'Binding not found'], 404);
        }

        DB::table('structure_manager_category_webhook')
            ->where('id', $row->id)
            ->update([
                'enabled'    => !$row->enabled,
                'updated_at' => now(),
            ]);

        return response()->json(['success' => true, 'enabled' => !$row->enabled]);
    }

    /**
     * GET /settings/notifications/roles
     * AJAX endpoint for the role dropdown when a Discord connector is installed.
     */
    public function listRoles()
    {
        return response()->json([
            'provider'  => DiscordRoleResolver::detectProvider(),
            'label'     => DiscordRoleResolver::providerLabel(),
            'available' => DiscordRoleResolver::isAvailable(),
            'roles'     => DiscordRoleResolver::listRoles(),
        ]);
    }
}
