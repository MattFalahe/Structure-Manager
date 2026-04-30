@extends('web::layouts.grids.12')

@section('title', 'Structure Board — Calendar Grid')

@section('full')

<style>
    .cb-grid-wrapper {
        background: #2a2f3a;
        border: 1px solid #454d55;
        border-radius: 6px;
        padding: 18px;
        color: #c2c7d0;
    }

    .cb-grid-header {
        display: flex;
        align-items: center;
        gap: 16px;
        margin-bottom: 16px;
        flex-wrap: wrap;
    }

    .cb-grid-title {
        font-size: 18px;
        font-weight: 600;
        color: #fff;
        margin: 0;
    }

    .cb-grid-nav {
        display: flex;
        gap: 6px;
        align-items: center;
    }

    .cb-grid-nav .btn {
        padding: 4px 10px;
    }

    .cb-grid-corp {
        margin-left: auto;
    }

    .cb-grid-table {
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
    }

    .cb-grid-table th {
        text-align: left;
        padding: 8px 10px;
        background: #1f242c;
        color: #aaa;
        font-weight: 500;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border: 1px solid #454d55;
    }

    .cb-grid-cell {
        vertical-align: top;
        height: 110px;
        padding: 6px;
        background: #2a2f3a;
        border: 1px solid #454d55;
        position: relative;
    }

    .cb-grid-cell.outside-month {
        background: #1f242c;
        opacity: 0.5;
    }

    .cb-grid-cell.is-today {
        background: rgba(40, 167, 69, 0.10);
        border-color: rgba(40, 167, 69, 0.5);
    }

    .cb-grid-day-number {
        font-size: 13px;
        color: #aaa;
        margin-bottom: 4px;
        font-weight: 500;
    }

    .cb-grid-cell.is-today .cb-grid-day-number {
        color: #28a745;
        font-weight: 700;
    }

    .cb-grid-event {
        display: block;
        font-size: 11px;
        padding: 2px 6px;
        margin-bottom: 2px;
        border-radius: 3px;
        background: #454d55;
        color: #c2c7d0;
        text-decoration: none;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        border-left: 3px solid #6c757d;
    }

    .cb-grid-event:hover {
        background: #5a6268;
        color: #fff;
        text-decoration: none;
    }

    .cb-grid-event.severity-warning  { border-left-color: #ffc107; }
    .cb-grid-event.severity-critical { border-left-color: #dc3545; }
    .cb-grid-event.severity-info     { border-left-color: #17a2b8; }

    .cb-grid-event.group-fuel      { background: rgba(255, 152, 0,  0.15); color: #ffc107; }
    .cb-grid-event.group-tactical  { background: rgba(220, 53, 69,  0.15); color: #ff7986; }
    .cb-grid-event.group-lifecycle { background: rgba(23, 162, 184, 0.15); color: #6cd4eb; }

    .cb-grid-overflow {
        font-size: 11px;
        color: #8b95a5;
        font-style: italic;
        margin-top: 2px;
    }

    .cb-grid-empty {
        text-align: center;
        padding: 40px 20px;
        color: #8b95a5;
    }

    .cb-grid-link-back {
        margin-bottom: 12px;
    }
</style>

<div class="cb-grid-wrapper">

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    <div class="cb-grid-link-back">
        <a href="{{ route('structure-manager.command-board.index') }}" class="btn btn-sm btn-outline-secondary">
            <i class="fas fa-list"></i> Back to timeline
        </a>
    </div>

    <div class="cb-grid-header">
        <h3 class="cb-grid-title">
            <i class="far fa-calendar"></i>
            {{ $monthStart->format('F Y') }}
        </h3>

        <div class="cb-grid-nav">
            <a href="{{ route('structure-manager.command-board.grid', ['month' => $prevMonthIso, 'corp' => $corpFilter]) }}" class="btn btn-sm btn-outline-secondary" title="Previous month">
                <i class="fas fa-chevron-left"></i>
            </a>
            <a href="{{ route('structure-manager.command-board.grid', ['corp' => $corpFilter]) }}" class="btn btn-sm btn-outline-secondary" title="Current month">
                Today
            </a>
            <a href="{{ route('structure-manager.command-board.grid', ['month' => $nextMonthIso, 'corp' => $corpFilter]) }}" class="btn btn-sm btn-outline-secondary" title="Next month">
                <i class="fas fa-chevron-right"></i>
            </a>
        </div>

        @if(count($userCorps) > 0 || $isBoardAdmin)
            <div class="cb-grid-corp">
                <form method="GET" action="{{ route('structure-manager.command-board.grid') }}" class="form-inline">
                    <input type="hidden" name="month" value="{{ $monthStart->format('Y-m') }}">
                    <select name="corp" class="form-control form-control-sm" onchange="this.form.submit()" style="background:#1f242c; color:#fff; border-color:#454d55;">
                        <option value="all_mine" @if($corpFilter == 'all_mine') selected @endif>All my corps</option>
                        @foreach($userCorps as $corp)
                            <option value="{{ $corp->corporation_id }}" @if((string)$corpFilter === (string)$corp->corporation_id) selected @endif>
                                {{ $corp->name }}@if($corp->ticker) [{{ $corp->ticker }}]@endif
                            </option>
                        @endforeach
                        @if($isBoardAdmin)
                            <option value="all_tracked" @if($corpFilter == 'all_tracked') selected @endif>— All tracked corps (admin) —</option>
                        @endif
                    </select>
                </form>
            </div>
        @endif
    </div>

    @php
        $today = \Carbon\Carbon::today();
        $maxEventsPerCell = 4;
    @endphp

    <table class="cb-grid-table">
        <thead>
            <tr>
                <th>Mon</th>
                <th>Tue</th>
                <th>Wed</th>
                <th>Thu</th>
                <th>Fri</th>
                <th>Sat</th>
                <th>Sun</th>
            </tr>
        </thead>
        <tbody>
            @for($week = 0; $week < 6; $week++)
                <tr>
                    @for($dayOfWeek = 0; $dayOfWeek < 7; $dayOfWeek++)
                        @php
                            $dayIndex = $week * 7 + $dayOfWeek;
                            $day = $gridDays[$dayIndex];
                            $dayKey = $day->format('Y-m-d');
                            $dayTimers = $byDay->get($dayKey, collect());
                            $isOutsideMonth = $day->month !== $monthStart->month;
                            $isToday = $day->isSameDay($today);
                            $cellClasses = 'cb-grid-cell';
                            if ($isOutsideMonth) $cellClasses .= ' outside-month';
                            if ($isToday)        $cellClasses .= ' is-today';
                        @endphp
                        <td class="{{ $cellClasses }}">
                            <div class="cb-grid-day-number">{{ $day->format('j') }}</div>
                            @foreach($dayTimers->take($maxEventsPerCell) as $timer)
                                <a href="{{ route('structure-manager.command-board.index') }}?days=60#timer-{{ $timer->id }}"
                                   class="cb-grid-event group-{{ $timer->category_group }} severity-{{ $timer->severity }}"
                                   title="{{ $timer->eve_time->format('H:i') }} EVE — {{ $timer->structure_name ?? $timer->structure_type }} — {{ str_replace('_', ' ', $timer->event_type) }}">
                                    <strong>{{ $timer->eve_time->format('H:i') }}</strong>
                                    {{ $timer->structure_name ?? $timer->structure_type ?? 'Timer' }}
                                </a>
                            @endforeach
                            @if($dayTimers->count() > $maxEventsPerCell)
                                <div class="cb-grid-overflow">
                                    +{{ $dayTimers->count() - $maxEventsPerCell }} more
                                </div>
                            @endif
                        </td>
                    @endfor
                </tr>
            @endfor
        </tbody>
    </table>

    <div style="margin-top:12px; color:#8b95a5; font-size:12px;">
        <strong>{{ $timers->count() }}</strong> active timer(s) across this 6-week window.
        Click a timer block to jump to it on the timeline view.
        Times are EVE / UTC.
    </div>
</div>

@stop
