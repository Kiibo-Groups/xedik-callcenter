@extends('layouts.app')

@section('title', 'Pipeline')
@section('page-title', 'Pipeline')
@section('page-subtitle', $totalLeads . ' leads in pipeline')

@section('content')

<div class="pipeline-page">

<div class="pipeline-toolbar">
    <div class="pipeline-scope-toggle">
        <a href="{{ route('pipeline.index', array_filter(['scope' => 'all',  'campaign_id' => $campaignId])) }}"
           class="pipeline-scope-btn {{ $scope === 'all' ? 'active' : '' }}">All Leads</a>
        <a href="{{ route('pipeline.index', array_filter(['scope' => 'mine', 'campaign_id' => $campaignId])) }}"
           class="pipeline-scope-btn {{ $scope === 'mine' ? 'active' : '' }}">My Leads</a>
    </div>

    <div class="pipeline-toolbar-right">
        @if($campaigns->count())
        <select id="pipelineCampaignFilter" class="form-control" style="width:220px">
            <option value="">All Campaigns</option>
            @foreach($campaigns as $c)
                <option value="{{ $c->id }}" {{ (string) $campaignId === (string) $c->id ? 'selected' : '' }}>{{ $c->name }}</option>
            @endforeach
        </select>
        @endif

        @if($tags->count())
        <div class="pipeline-tag-filter-wrap" id="pipelineTagFilterWrap">
            <button type="button" id="pipelineTagFilterBtn" class="pipeline-tag-filter-btn">
                <i class="bi bi-tags"></i> Tags
                @if(count($tagIds))<span class="pipeline-tag-filter-badge">{{ count($tagIds) }}</span>@endif
            </button>
            <div id="pipelineTagFilterDropdown" class="pipeline-tag-filter-dropdown" style="display:none">
                <div class="pipeline-tag-filter-list scrollbar-none">
                    @foreach($tags as $t)
                    <label class="pipeline-tag-filter-item">
                        <input type="checkbox" class="pipeline-tag-checkbox" value="{{ $t->id }}" {{ in_array($t->id, $tagIds) ? 'checked' : '' }}>
                        <span class="pipeline-tag-pill" style="background:{{ $t->bg_color }};color:{{ $t->color }}">{{ $t->name }}</span>
                    </label>
                    @endforeach
                </div>
                <div class="pipeline-tag-filter-actions">
                    <button type="button" id="pipelineTagFilterClear" class="btn btn-secondary btn-sm">Clear</button>
                    <button type="button" id="pipelineTagFilterApply" class="btn btn-primary btn-sm">Apply</button>
                </div>
            </div>
        </div>
        @endif
    </div>
</div>

@php
    $pipelineAvatarPalette = ['#5B3FB8', '#185FA5', '#0F6E56', '#A52A57', '#C2410C', '#3B6D11', '#1F6E66', '#7C3AED'];
    $pipelineSourceStyles = [
        'whatsapp' => ['bg' => '#E7F4DC', 'color' => '#3B6D11', 'icon' => 'whatsapp'],
        'email'    => ['bg' => '#E4EFFB', 'color' => '#185FA5', 'icon' => 'envelope'],
        'phone'    => ['bg' => '#FBEFD9', 'color' => '#92560A', 'icon' => 'telephone'],
        'web'      => ['bg' => '#EFEAFB', 'color' => '#5B3FB8', 'icon' => 'globe'],
        'webchat'  => ['bg' => '#E3F2F1', 'color' => '#1F6E66', 'icon' => 'chat-dots'],
        'dialer'   => ['bg' => '#FBE7EE', 'color' => '#A52A57', 'icon' => 'telephone-outbound'],
    ];
    $pipelineSourceDefault = ['bg' => '#EEF0F2', 'color' => '#5A6472', 'icon' => 'circle'];
@endphp

<div class="pipeline-board-wrap" id="pipelineBoardWrap">
    <button type="button" class="pipeline-board-arrow pipeline-board-arrow-left is-edge" id="pipelineArrowLeft" aria-label="Scroll left">
        <i class="bi bi-chevron-left"></i>
    </button>

    <div class="pipeline-board" id="pipelineBoard">
    @foreach($stages as $stage)
        @php $stageLeads = $leadsByStatus->get($stage->slug, collect()); @endphp
        <div class="pipeline-column">
            <div class="pipeline-column-header" data-status-id="{{ $stage->id }}" data-leads-count="{{ $statusLeadsCounts[$stage->slug] ?? 0 }}">
                @if($canManageStatuses)
                    <i class="bi bi-grip-vertical pipeline-column-drag-handle" title="Drag to reorder"></i>
                @endif
                <span class="pipeline-dot" style="background:{{ $stage->bg_color }}"></span>
                <span class="pipeline-column-title">{{ $stage->name }}</span>
                <span class="pipeline-column-count" data-role="count">{{ $stageLeads->count() }}</span>
                @if($canManageStatuses)
                    @php $stageHasLeads = ($statusLeadsCounts[$stage->slug] ?? 0) > 0; @endphp
                    <div class="pipeline-column-menu">
                        <button type="button" class="pipeline-column-menu-btn" data-role="column-menu-btn" title="Column options">
                            <i class="bi bi-three-dots-vertical"></i>
                        </button>
                        <div class="pipeline-column-menu-dropdown" data-role="column-menu-dropdown">
                            <button type="button" class="pipeline-column-menu-item" data-action="new-status">
                                <i class="bi bi-plus-circle"></i> New Status
                            </button>
                            @if($stageHasLeads)
                                <button type="button" class="pipeline-column-menu-item pipeline-column-menu-item-danger" disabled title="Cannot delete — has leads">
                                    <i class="bi bi-trash"></i> Delete Status
                                </button>
                            @else
                                <button type="button" class="pipeline-column-menu-item pipeline-column-menu-item-danger" data-action="delete-status">
                                    <i class="bi bi-trash"></i> Delete Status
                                </button>
                            @endif
                        </div>
                    </div>
                @endif
            </div>
            <div class="pipeline-column-value" data-role="value">
                ${{ number_format((float) $stageLeads->sum('value'), 2) }}
            </div>
            <div class="pipeline-column-body" data-status="{{ $stage->slug }}">
                @foreach($stageLeads as $lead)
                    @php
                        $initials = strtoupper(mb_substr($lead->first_name ?? '', 0, 1));
                        $avatarColor = $pipelineAvatarPalette[crc32($lead->full_name) % count($pipelineAvatarPalette)];
                        $sourceKey = strtolower(trim((string) $lead->source));
                        $sourceStyle = $pipelineSourceStyles[$sourceKey] ?? $pipelineSourceDefault;
                    @endphp
                    <div class="pipeline-card"
                         style="border-left: 3px solid {{ $stage->bg_color }}"
                         data-lead-id="{{ $lead->id }}"
                         data-name="{{ $lead->full_name }}"
                         data-phone="{{ $lead->phone }}"
                         data-email="{{ $lead->email }}"
                         data-value="{{ $lead->value ?? 0 }}"
                         data-source="{{ $lead->source }}"
                         data-agent="{{ $lead->agent->name ?? '' }}"
                         data-campaign="{{ $lead->campaign->name ?? '' }}"
                         data-status-slug="{{ $stage->slug }}"
                         data-status-label="{{ $stage->name }}"
                         data-status-style="{{ $stage->badge_style }}"
                         data-lead-url="{{ route('leads.show', $lead) }}">
                        <div class="pipeline-card-top">
                            <div class="pipeline-card-avatar" style="background:{{ $avatarColor }}">{{ $initials }}</div>
                            <div class="pipeline-card-info">
                                <div class="pipeline-card-name">{{ $lead->full_name }}</div>
                                @if($lead->phone)
                                    <div class="pipeline-card-phone">{{ $lead->phone }}</div>
                                @endif
                            </div>
                            <div class="pipeline-card-time">{{ $lead->updated_at?->diffForHumans(null, false, true) }}</div>
                        </div>
                        <div class="pipeline-card-footer">
                            @if($lead->source)
                                <span class="pipeline-card-source" style="background:{{ $sourceStyle['bg'] }};color:{{ $sourceStyle['color'] }}">
                                    <i class="bi bi-{{ $sourceStyle['icon'] }}"></i> {{ $lead->source }}
                                </span>
                            @else
                                <span></span>
                            @endif
                            @if($lead->value)
                                <span class="pipeline-card-value">${{ number_format((float) $lead->value, 2) }}</span>
                            @else
                                <span class="pipeline-card-novalue">$0.00</span>
                            @endif
                        </div>
                        @if($lead->tags->isNotEmpty())
                        <div class="pipeline-card-tags">
                            @foreach($lead->tags->take(3) as $tag)
                                <span class="pipeline-tag-pill" style="background:{{ $tag->bg_color }};color:{{ $tag->color }}">{{ $tag->name }}</span>
                            @endforeach
                            @if($lead->tags->count() > 3)
                                <span class="pipeline-tag-more">+{{ $lead->tags->count() - 3 }}</span>
                            @endif
                        </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endforeach
    </div>

    <button type="button" class="pipeline-board-arrow pipeline-board-arrow-right" id="pipelineArrowRight" aria-label="Scroll right">
        <i class="bi bi-chevron-right"></i>
    </button>
</div>

@if($canManageStatuses)
{{-- ── INLINE "NEW STATUS" COLUMN TEMPLATE — clonado por pipeline.js ── --}}
<template id="pipelineNewStatusTemplate">
    <div class="pipeline-column pipeline-column-new">
        <div class="pipeline-column-header">
            <span class="pipeline-column-title">New Status</span>
        </div>
        <div class="pipeline-column-new-body">
            <div class="form-group mb-12">
                <label class="form-label">Status name</label>
                <input type="text" class="form-control" data-role="ns-name" placeholder="e.g. Negotiation" maxlength="100">
            </div>

            <div class="form-row cols-2 mb-12">
                <div class="form-group mb-0">
                    <label class="form-label">Text color</label>
                    <div class="color-row">
                        <input type="color" class="color-picker" data-role="ns-color" value="#4f46e5">
                        <input type="text" class="form-control color-hex" data-role="ns-color-hex" value="#4f46e5">
                    </div>
                </div>
                <div class="form-group mb-0">
                    <label class="form-label">Background</label>
                    <div class="color-row">
                        <input type="color" class="color-picker" data-role="ns-bg" value="#e0e7ff">
                        <input type="text" class="form-control color-hex" data-role="ns-bg-hex" value="#e0e7ff">
                    </div>
                </div>
            </div>

            <div class="preview-box mb-12">
                <span class="cell-muted">Preview:</span>
                <span class="badge" data-role="ns-preview" style="background:#e0e7ff;color:#4f46e5">New Status</span>
            </div>

            <div class="form-group mb-12">
                <label class="form-label">Counts as</label>
                <select class="form-control" data-role="ns-counts-as">
                    <option value="none">None (open status)</option>
                    <option value="won">Won 🏆</option>
                    <option value="lost">Lost ✗</option>
                </select>
            </div>

            <div class="invalid-feedback" data-role="ns-error"></div>

            <div class="pipeline-column-new-actions">
                <button type="button" class="btn btn-secondary btn-sm" data-role="ns-cancel">Cancel</button>
                <button type="button" class="btn btn-primary btn-sm" data-role="ns-create">Create</button>
            </div>
        </div>
    </div>
</template>
@endif

{{-- SUMMARY — estructura plana (misma lógica que el board: flex + gap:14px + items 289px) --}}
<div class="pipeline-summary" id="pipelineSummary">
    <div class="pipeline-summary-item">
        <div class="pipeline-summary-label">Total Leads</div>
        <div class="pipeline-summary-value" data-role="summary-total-leads">{{ $totalLeads }}</div>
    </div>
    <div class="pipeline-summary-item">
        <div class="pipeline-summary-label">In Pipeline</div>
        <div class="pipeline-summary-value" data-role="summary-open-value">${{ number_format($openValue, 2) }}</div>
    </div>
    <div class="pipeline-summary-item">
        <div class="pipeline-summary-label">Won</div>
        <div class="pipeline-summary-value" data-role="summary-won">{{ $wonCount }} &middot; ${{ number_format($wonValue, 2) }}</div>
    </div>
    <div class="pipeline-summary-item">
        <div class="pipeline-summary-label">Conversion</div>
        <div class="pipeline-summary-value" data-role="summary-conversion">{{ $conversion }}%</div>
    </div>
    <div class="pipeline-summary-item">
        <div class="pipeline-summary-label">Avg. Deal</div>
        <div class="pipeline-summary-value" data-role="summary-avg-ticket">${{ number_format($avgTicket, 2) }}</div>
    </div>

    <div class="pipeline-summary-donut" id="pipelineDonutCard">
        <div class="pipeline-donut-title">Distribution by Status</div>
        <div class="pipeline-donut-body">
            <div class="pipeline-donut-chart-wrap">
                <canvas id="pipelineDonutChart" width="96" height="96"></canvas>
                <div class="pipeline-donut-center" data-role="donut-total">{{ $totalLeads }}</div>
            </div>
            <div class="pipeline-donut-legend">
                @foreach($stageDistribution as $stage)
                <div class="pipeline-donut-legend-item" data-status="{{ $stage['slug'] }}">
                    <span class="pipeline-donut-legend-dot" style="background:{{ $stage['color'] }}"></span>
                    <span class="pipeline-donut-legend-label">{{ $stage['label'] }}</span>
                    <span class="pipeline-donut-legend-count" data-role="donut-count">{{ $stage['count'] }}</span>
                </div>
                @endforeach
            </div>
        </div>
    </div>
</div>

</div>

{{-- SIDE PANEL — usa window.LeadPanel (mismo que la consola) --}}
<div class="pipeline-panel-overlay" id="pipelinePanelOverlay"></div>
<div class="pipeline-panel" id="pipelinePanel">
    <div class="pipeline-panel-header">
        <span class="card-title"><i class="bi bi-person-vcard icon-primary"></i> Lead Details</span>
        <button class="modal-close" id="pipelinePanelClose">&times;</button>
    </div>
    <div id="pipelinePanelBody" style="flex:1;overflow:hidden;display:flex;flex-direction:column;min-height:0;"></div>
</div>

@endsection

@push('styles')
<style>
    /* Pipeline Kanban — estilos específicos del módulo (excepción documentada, similar a console/index) */
    .pipeline-toolbar { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 16px; flex-wrap: wrap; }
    .pipeline-toolbar-right { display: flex; align-items: center; gap: 8px; }
    .pipeline-scope-toggle { display: flex; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-sm); overflow: hidden; }
    .pipeline-scope-btn { padding: 8px 16px; font-size: 13px; font-weight: 500; color: var(--text-mid); text-decoration: none; transition: background .2s, color .2s; }
    .pipeline-scope-btn.active { background: var(--primary); color: #fff; }
    .pipeline-scope-btn:not(.active):hover { background: var(--bg-row-hover); }

    .pipeline-page { max-width: 100%; overflow-x: hidden; }
    .pipeline-board-wrap { position: relative; width: 100%; max-width: 100%; }
    .pipeline-board {
        display: flex; gap: 14px; overflow-x: auto; padding-bottom: 8px; align-items: stretch;
        width: 100%; max-width: 100%; min-width: 0;
        height: max(calc(100vh - 320px), 200px);
        scrollbar-width: none;
        -ms-overflow-style: none;
        scroll-snap-type: x mandatory;
    }
    .pipeline-board::-webkit-scrollbar { display: none; }
    .pipeline-board.no-snap { scroll-snap-type: none; }
    .pipeline-column { flex: 0 0 289px; background: var(--bg-th); border: 1px solid var(--border); border-radius: var(--radius); display: flex; flex-direction: column; max-height: calc(100vh - 320px); min-height: 200px; scroll-snap-align: start; }

    .pipeline-board-arrow {
        position: absolute; top: 50%; transform: translateY(-50%);
        width: 36px; height: 36px; border-radius: 50%;
        display: flex; align-items: center; justify-content: center;
        background: var(--bg-card); border: 1px solid var(--border); box-shadow: var(--shadow-lg);
        color: var(--text-strong); font-size: 16px; cursor: pointer;
        opacity: 0; transition: opacity .15s; z-index: 5;
    }
    .pipeline-board-arrow-left { left: 0; }
    .pipeline-board-arrow-right { right: 0; }
    .pipeline-board-wrap:hover .pipeline-board-arrow:not(.is-edge) { opacity: 1; }
    .pipeline-column-header { display: flex; align-items: center; gap: 8px; padding: 12px 14px 4px; }
    .pipeline-column-drag-handle { cursor: grab; color: var(--text-muted); font-size: 14px; flex-shrink: 0; }
    .pipeline-column-drag-handle:active { cursor: grabbing; }
    .pipeline-column.sortable-ghost { opacity: .4; }
    .pipeline-column.sortable-drag { box-shadow: var(--shadow-lg); cursor: grabbing; }
    .pipeline-dot { width: 10px; height: 10px; border-radius: 50%; flex-shrink: 0; }
    .pipeline-column-title { font-weight: 600; font-size: 13px; color: var(--text-strong); flex: 1; }
    .pipeline-column-count { font-size: 12px; font-weight: 600; color: var(--text-mid); background: var(--bg-card); border-radius: 999px; padding: 1px 8px; }

    /* Column ⋮ menu — New Status / Delete Status */
    .pipeline-column-menu { position: relative; flex-shrink: 0; }
    .pipeline-column-menu-btn { background: none; border: none; color: var(--text-muted); font-size: 14px; line-height: 1; padding: 3px 5px; border-radius: var(--radius-sm); cursor: pointer; }
    .pipeline-column-menu-btn:hover { background: var(--bg-row-hover); color: var(--text-strong); }
    .pipeline-column-menu-dropdown { display: none; position: absolute; top: 100%; right: 0; margin-top: 4px; z-index: 10; min-width: 160px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-sm); box-shadow: var(--shadow-lg); padding: 4px; }
    .pipeline-column-menu-dropdown.active { display: block; }
    .pipeline-column-menu-item { display: flex; align-items: center; gap: 8px; width: 100%; padding: 7px 10px; border: none; background: none; text-align: left; font-size: 13px; color: var(--text-strong); border-radius: var(--radius-sm); cursor: pointer; }
    .pipeline-column-menu-item:hover { background: var(--bg-row-hover); }
    .pipeline-column-menu-item-danger { color: var(--danger); }
    .pipeline-column-menu-item-danger:hover { background: rgba(239,68,68,.1); }
    .pipeline-column-menu-item:disabled { color: var(--text-muted); cursor: not-allowed; opacity: .6; }
    .pipeline-column-menu-item:disabled:hover { background: none; }

    /* Inline "New Status" column */
    .pipeline-column-new { border-style: dashed; border-color: var(--primary); }
    .pipeline-column-new-body { padding: 12px 14px; flex: 1; overflow-y: auto; }
    .pipeline-column-new-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 4px; }

    .pipeline-column-value { font-size: 12px; color: var(--text-muted); padding: 0 14px 10px; }
    .pipeline-column-body {
        flex: 1; overflow-y: auto; padding: 2px 10px 10px; display: flex; flex-direction: column; gap: 8px; min-height: 60px;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    .pipeline-column-body::-webkit-scrollbar { display: none; }

    .pipeline-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 10px 12px; cursor: grab; box-shadow: var(--shadow); transition: box-shadow .15s; }
    .pipeline-card:hover { box-shadow: 0 0 0 1px rgba(79,70,229,.5), 0 2px 8px rgba(0,0,0,.08); }
    .pipeline-card.pipeline-card-active { box-shadow: 0 0 0 1px rgba(79,70,229,.5), 0 2px 8px rgba(0,0,0,.08); }
    .pipeline-card:active { cursor: grabbing; }
    .pipeline-card.sortable-ghost { opacity: .4; }
    .pipeline-card.sortable-drag { box-shadow: var(--shadow-lg); }

    .pipeline-card-top { display: flex; align-items: flex-start; gap: 8px; margin-bottom: 8px; }
    .pipeline-card-avatar { width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0; display: flex; align-items: center; justify-content: center; color: #fff; font-size: 12px; font-weight: 700; }
    .pipeline-card-info { flex: 1; min-width: 0; }
    .pipeline-card-name { font-weight: 600; font-size: 13px; color: var(--text-strong); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pipeline-card-phone { font-size: 12px; color: var(--text-mid); display: flex; align-items: center; gap: 4px; margin-top: 2px; }
    .pipeline-card-time { font-size: 10px; color: var(--text-muted); white-space: nowrap; flex-shrink: 0; margin-left: auto; padding-top: 2px; }

    .pipeline-card-footer { display: flex; align-items: center; justify-content: space-between; gap: 6px; }
    .pipeline-card-source { display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 999px; white-space: nowrap; }
    .pipeline-card-value { font-size: 12px; font-weight: 700; color: var(--success); }
    .pipeline-card-novalue { font-size: 11px; color: var(--text-muted); font-style: italic; }

    .pipeline-card-tags { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 7px; }
    .pipeline-tag-pill { display: inline-flex; align-items: center; padding: 2px 7px; border-radius: 20px; font-size: 11px; font-weight: 500; white-space: nowrap; }
    .pipeline-tag-more { display: inline-flex; align-items: center; padding: 2px 7px; border-radius: 20px; font-size: 11px; font-weight: 500; background: var(--bg-th); color: var(--text-muted); }

    /* Tag filter dropdown in toolbar */
    .pipeline-tag-filter-wrap { position: relative; }
    .pipeline-tag-filter-btn { display: inline-flex; align-items: center; gap: 6px; height: 38px; padding: 0 14px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-sm); font-size: 13px; font-weight: 500; color: var(--text-mid); cursor: pointer; }
    .pipeline-tag-filter-btn:hover { background: var(--bg-row-hover); color: var(--text-strong); }
    .pipeline-tag-filter-badge { display: inline-flex; align-items: center; justify-content: center; width: 18px; height: 18px; border-radius: 50%; background: var(--primary); color: #fff; font-size: 11px; font-weight: 700; }
    .pipeline-tag-filter-dropdown { position: absolute; top: calc(100% + 4px); right: 0; z-index: 20; min-width: 220px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius-sm); box-shadow: var(--shadow-lg); }
    .pipeline-tag-filter-list { max-height: 220px; overflow-y: auto; padding: 6px 0; }
    .pipeline-tag-filter-item { display: flex; align-items: center; gap: 8px; padding: 5px 12px; cursor: pointer; }
    .pipeline-tag-filter-item:hover { background: var(--bg-row-hover); }
    .pipeline-tag-checkbox { flex-shrink: 0; cursor: pointer; }
    .pipeline-tag-filter-actions { display: flex; justify-content: flex-end; gap: 8px; padding: 8px 12px; border-top: 1px solid var(--border); }

    /* gap:14px = mismo que .pipeline-board; items flex:0 0 289px = mismo que .pipeline-column */
    .pipeline-summary { display: flex; gap: 14px; align-items: stretch; overflow-x: hidden; }
    /* height:100% en flex-row con altura implícita se resuelve como auto, anula stretch.
       Se omite; align-items:stretch del padre es suficiente para igualar al donut. */
    .pipeline-summary-item { background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 16px; box-shadow: var(--shadow); flex: 0 0 289px; min-width: 0; overflow: hidden; display: flex; flex-direction: column; min-height: 100px; }
    .pipeline-summary-label { font-size: 11px; color: var(--text-muted); margin-bottom: 8px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pipeline-summary-value { font-size: 20px; font-weight: 700; color: var(--text-strong); white-space: nowrap; flex: 1; display: flex; align-items: center; justify-content: center; }

    /* Donut — distribution by status */
    .pipeline-summary-donut { flex: 0 0 289px; width: 289px; max-width: 289px; background: var(--bg-card); border: 1px solid var(--border); border-radius: var(--radius); padding: 12px 16px; box-shadow: var(--shadow); display: flex; flex-direction: column; overflow: hidden; }
    .pipeline-donut-title { font-size: 12px; color: var(--text-muted); margin-bottom: 8px; }
    .pipeline-donut-body { display: flex; align-items: center; gap: 14px; flex: 1; min-width: 0; }
    .pipeline-donut-chart-wrap { position: relative; width: 96px; height: 96px; flex-shrink: 0; }
    .pipeline-donut-center {
        position: absolute; top: 0; left: 0; width: 100%; height: 100%;
        display: flex; align-items: center; justify-content: center;
        font-size: 18px; font-weight: 700; color: var(--text-strong);
        pointer-events: none; z-index: 1;
    }
    .pipeline-donut-tooltip {
        position: fixed; z-index: 1000; pointer-events: none;
        background: rgba(30,41,59,.92); color: #fff;
        font-size: 11px; font-weight: 600;
        padding: 4px 8px; border-radius: 6px; white-space: nowrap;
        opacity: 0; transition: opacity .1s ease;
    }
    .pipeline-donut-legend {
        flex: 1; display: flex; flex-direction: column; gap: 0; min-width: 0;
        max-height: 96px; overflow-y: auto;
        scrollbar-width: none;
        -ms-overflow-style: none;
    }
    .pipeline-donut-legend::-webkit-scrollbar { display: none; }
    .pipeline-donut-legend-item { display: flex; align-items: center; justify-content: space-between; gap: 6px; font-size: 12px; line-height: 18px; color: var(--text-mid); padding: 3px 0; white-space: nowrap; }
    .pipeline-donut-legend-dot { width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0; }
    .pipeline-donut-legend-label { flex: 1; min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .pipeline-donut-legend-count { font-weight: 600; color: var(--text-strong); flex-shrink: 0; }

    /* Side panel */
    .pipeline-panel-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.4); z-index: 199; display: none; }
    .pipeline-panel-overlay.active { display: block; }
    .pipeline-panel { position: fixed; top: 0; right: -340px; width: 320px; height: 100%; background: var(--bg-card); border-left: 1px solid var(--border); z-index: 200; transition: right .25s; display: flex; flex-direction: column; box-shadow: var(--shadow-lg); }
    .pipeline-panel.active { right: 0; }
    .pipeline-panel-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 18px; border-bottom: 1px solid var(--border); }
    /* pipelinePanelBody aloja el LeadPanel completo (mismo que la consola) */
    #pipelinePanelBody .lead-field-row { border-bottom-color: var(--border-light); }

    @media (max-width: 1100px) {
        .pipeline-summary { flex-wrap: wrap; }
        .pipeline-summary-item { flex: 1 1 240px; }
        .pipeline-summary-donut { flex: 1 1 240px; }
    }

    @media (max-width: 768px) {
        .pipeline-summary-item { flex: 1 1 calc(50% - 7px); }
        .pipeline-panel { width: 85%; right: -85%; }
    }
</style>
@endpush

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/canvas-confetti@1.9.3/dist/confetti.browser.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.5.1/dist/chart.umd.min.js"></script>
<script>
window.PipelineConfig = {
    updateUrlTemplate: "{{ route('pipeline.updateStage', ['lead' => 'LEAD_ID']) }}",
    leadDetailUrl: "{{ route('console.leadDetail', ['lead' => 'LEAD_ID']) }}",
    reorderStatusUrl: "{{ route('pipeline.reorderStatuses') }}",
    storeStatusUrl: "{{ route('statuses.store') }}",
    destroyStatusUrlTemplate: "{{ route('statuses.destroy', ['status' => 'STATUS_ID']) }}",
    indexUrl: "{{ route('pipeline.index') }}",
    scope: @json($scope),
    campaignId: @json($campaignId),
    tagIds: @json($tagIds),
    wonSlugs: @json($wonSlugs),
    lostSlugs: @json($lostSlugs),
    stages: @json($stages->mapWithKeys(fn($s) => [$s->slug => ['name' => $s->name, 'badge_style' => $s->badge_style, 'bg_color' => $s->bg_color]])),
    stageDistribution: @json($stageDistribution),
    canManageStatuses: @json($canManageStatuses),
};
</script>
<script>
// Colapsa el sidebar automáticamente en Pipeline para maximizar el ancho del board
// No modifica localStorage para que la preferencia original se restaure al salir
(function () {
    var sidebar = document.getElementById('sidebar');
    if (sidebar && !sidebar.classList.contains('collapsed')) {
        sidebar.style.transition = 'none';
        sidebar.classList.add('collapsed');
        document.documentElement.style.setProperty('--sidebar-w', '64px');
        setTimeout(function () { sidebar.style.transition = ''; }, 50);
    }
}());
</script>
<script src="{{ asset('js/console-lead-panel.js') }}"></script>
<script>
window.LeadPanel.init(@json($leadStatuses));
</script>
<script src="{{ asset('js/pipeline.js') }}"></script>
@endpush
