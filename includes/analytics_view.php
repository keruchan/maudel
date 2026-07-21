<?php
/**
 * ============================================================
 * File     : includes/analytics_view.php
 * Project  : SKed - Youth Profiling System for Event Management
 * Purpose  : Shared renderer for the deep-analytics page body (P16) used
 *            by pages/{sk,ppsk,dilg}/analytics.php. Three tabs matching the
 *            analytics maturity model — Descriptive / Predictive /
 *            Prescriptive — each with Chart.js charts and a plain-language
 *            insight note. Keeps the ~300 lines of chart markup + JS in one
 *            place; callers pass a pre-computed data bundle and render their
 *            own page shell (head/nav/header) + the Chart.js CDN include.
 *
 * No geographic/mapping layer (deliberately excluded per the request).
 * ============================================================
 */

require_once __DIR__ . '/analytics.php';
require_once __DIR__ . '/view.php';

/** Civic-palette hex values reused by every chart. */
function sked_analytics_palette(): array
{
    return [
        'civic' => '#4338ca', 'iris' => '#818cf8', 'teal' => '#0f766e',
        'amber' => '#b45309', 'coral' => '#c02748', 'sky' => '#0369a1',
        'moss' => '#4d7c0f', 'plum' => '#7c3aed', 'slate' => '#64748b', 'rose' => '#be185d',
    ];
}

/** Builds a {labels,data,colors} distribution payload, skipping zero slices. */
function sked_analytics_dist_payload(array $counts, array $labelMap = []): array
{
    $palette = array_values(sked_analytics_palette());
    $out = ['labels' => [], 'data' => [], 'colors' => []];
    $i = 0;
    foreach ($counts as $key => $count) {
        if ((int) $count === 0) { continue; }
        $out['labels'][] = $labelMap[$key] ?? ucwords(str_replace('_', ' ', (string) $key));
        $out['data'][] = (int) $count;
        $out['colors'][] = $palette[$i++ % count($palette)];
    }
    return $out;
}

/** Assembles the full JSON payload handed to Chart.js. */
function sked_analytics_chart_payload(array $bundle, array $distributions): array
{
    return [
        'labels' => $bundle['labels'],
        'histLen' => $bundle['history_length'],
        'series' => [
            'registrations' => ['history' => $bundle['registrations']['history'], 'forecast' => $bundle['registrations']['forecast']],
            'participations' => ['history' => $bundle['participations']['history'], 'forecast' => $bundle['participations']['forecast']],
            'events_held' => ['history' => $bundle['events_held']['history'], 'forecast' => $bundle['events_held']['forecast']],
            'poll_votes' => ['history' => $bundle['poll_votes']['history'], 'forecast' => $bundle['poll_votes']['forecast']],
        ],
        'dist' => [
            'verification' => sked_analytics_dist_payload($distributions['verification'], ['pending' => 'Pending', 'active' => 'Verified', 'rejected' => 'Rejected']),
            'event_status' => sked_analytics_dist_payload($distributions['event_status']),
            'interest' => sked_analytics_dist_payload($distributions['interest']),
            'participation' => sked_analytics_dist_payload($distributions['participation'], ['interested' => 'Interested', 'registered' => 'Registered', 'attended' => 'Attended', 'no_show' => 'No-show']),
        ],
    ];
}

/** A trend chip (rising/flat/falling) for the forecast summary table. */
function sked_analytics_trend_chip(array $meta): string
{
    $t = $meta['trend'];
    $icon = $t === 'rising' ? 'bi-arrow-up-right' : ($t === 'falling' ? 'bi-arrow-down-right' : 'bi-dash');
    return '<span class="forecast-chip ' . e($t) . '"><i class="bi ' . $icon . '"></i>' . e(ucfirst($t)) . '</span>';
}

/**
 * Render the analytics tabs + panes + Chart.js init script.
 *
 * @param array<string,mixed> $bundle           sked_analytics_forecast_bundle()
 * @param array<string,mixed> $distributions    sked_analytics_distributions()
 * @param array<string,mixed> $profiling         sked_analytics_profiling_completion()
 * @param array<int,array>    $ranked            sked_recommend_categories()
 * @param array<int,array>    $recommendations   sked_analytics_recommendations()
 * @param string              $scopeNote         short phrase, e.g. "your barangay" / "the municipality"
 * @param array<string,mixed> $feedbackInsights  sked_feedback_insights_bundle()
 */
function sked_render_analytics_body(array $bundle, array $distributions, array $profiling, array $ranked, array $recommendations, string $scopeNote, array $feedbackInsights = []): void
{
    $chartData = sked_analytics_chart_payload($bundle, $distributions);

    // Predictive summary rows. invert_good: for these SKed series, "rising" is
    // good (more youth engaged), so the rising chip should read civic/positive.
    $forecastRows = [
        ['series' => 'registrations', 'label' => 'Youth registrations', 'invert_good' => true],
        ['series' => 'participations', 'label' => 'Event participation', 'invert_good' => true],
        ['series' => 'events_held', 'label' => 'Events held', 'invert_good' => true],
        ['series' => 'poll_votes', 'label' => 'Poll votes cast', 'invert_good' => true],
    ];

    // Voice of the Youth tab data (word cloud + sentiment). Defaults keep this
    // tab safe to render even if a caller hasn't supplied it yet.
    $sentiment = $feedbackInsights['sentiment'] ?? ['total' => 0, 'positive' => 0, 'neutral' => 0, 'negative' => 0, 'pct' => ['positive' => 0.0, 'neutral' => 0.0, 'negative' => 0.0], 'overall' => 'neutral'];
    $cloudWords = $feedbackInsights['words'] ?? [];
    $recentFeedback = $feedbackInsights['recent'] ?? [];
    $sentimentHeadlines = [
        'positive' => ['icon' => 'bi-emoji-smile', 'label' => 'Mostly Positive'],
        'negative' => ['icon' => 'bi-emoji-frown', 'label' => 'Mostly Negative'],
        'neutral'  => ['icon' => 'bi-emoji-neutral', 'label' => 'Mixed / Neutral'],
    ];
    $sentimentTone = $sentimentHeadlines[$sentiment['overall']] ?? $sentimentHeadlines['neutral'];
    ?>
    <ul class="nav analytics-tabs mb-3" id="analyticsTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-descriptive" data-bs-toggle="tab" data-bs-target="#pane-descriptive" data-tab="descriptive" type="button" role="tab"><i class="bi bi-bar-chart me-1"></i>Descriptive</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-predictive" data-bs-toggle="tab" data-bs-target="#pane-predictive" data-tab="predictive" type="button" role="tab"><i class="bi bi-graph-up-arrow me-1"></i>Predictive</button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-prescriptive" data-bs-toggle="tab" data-bs-target="#pane-prescriptive" data-tab="prescriptive" type="button" role="tab"><i class="bi bi-lightbulb me-1"></i>Prescriptive<span class="badge text-bg-secondary ms-1"><?php echo count($recommendations); ?></span></button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-voice" data-bs-toggle="tab" data-bs-target="#pane-voice" data-tab="voice" type="button" role="tab"><i class="bi bi-chat-square-text me-1"></i>Voice of the Youth<span class="badge text-bg-secondary ms-1"><?php echo (int) ($feedbackInsights['corpus_count'] ?? 0); ?></span></button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- ============ DESCRIPTIVE ============ -->
        <div class="tab-pane fade show active" id="pane-descriptive" role="tabpanel">
            <div class="alert alert-light border d-flex gap-2 align-items-start mb-3" role="note">
                <i class="bi bi-info-circle text-primary mt-1"></i>
                <div><strong>What's been happening.</strong> Monthly youth activity across <?php echo e($scopeNote); ?> over the last 12 months, and how current membership and events are distributed. Hover any chart for exact figures.</div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-lg-6">
                    <section class="docket-panel h-100">
                        <div class="section-heading"><h2 class="h6 mb-0">Youth Registrations &mdash; monthly</h2></div>
                        <div class="chart-frame"><canvas id="d_reg"></canvas></div>
                        <p class="small text-secondary mt-2 mb-0"><i class="bi bi-lightbulb me-1"></i><span id="ins_reg">New KK sign-ups per month — a rising line means the platform is reaching more youth.</span></p>
                    </section>
                </div>
                <div class="col-lg-6">
                    <section class="docket-panel h-100">
                        <div class="section-heading"><h2 class="h6 mb-0">Event Participation &mdash; monthly</h2></div>
                        <div class="chart-frame"><canvas id="d_part"></canvas></div>
                        <p class="small text-secondary mt-2 mb-0"><i class="bi bi-lightbulb me-1"></i><span id="ins_part">Event sign-ups (joins/registrations) per month — the clearest signal of active engagement.</span></p>
                    </section>
                </div>
                <div class="col-lg-6">
                    <section class="docket-panel h-100">
                        <div class="section-heading"><h2 class="h6 mb-0">Membership Status</h2></div>
                        <div class="chart-frame chart-frame-sm"><canvas id="d_verif"></canvas></div>
                        <p class="small text-secondary mt-2 mb-0"><i class="bi bi-lightbulb me-1"></i>Verified vs. pending vs. rejected KK members. A large pending slice means a verification backlog to clear.</p>
                    </section>
                </div>
                <div class="col-lg-6">
                    <section class="docket-panel h-100">
                        <div class="section-heading"><h2 class="h6 mb-0">Youth Interests</h2></div>
                        <div class="chart-frame chart-frame-sm"><canvas id="d_interest"></canvas></div>
                        <p class="small text-secondary mt-2 mb-0"><i class="bi bi-lightbulb me-1"></i>Which "Center of Participation" areas youth picked during KK Profiling — where to aim programs.</p>
                    </section>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-md-4">
                    <section class="docket-panel h-100">
                        <div class="section-heading"><h2 class="h6 mb-0">Events Held &mdash; monthly</h2></div>
                        <div class="chart-frame chart-frame-sm"><canvas id="d_events"></canvas></div>
                    </section>
                </div>
                <div class="col-md-4">
                    <section class="docket-panel h-100">
                        <div class="section-heading"><h2 class="h6 mb-0">Participation Outcomes</h2></div>
                        <div class="chart-frame chart-frame-sm"><canvas id="d_partmix"></canvas></div>
                    </section>
                </div>
                <div class="col-md-4">
                    <section class="docket-panel h-100">
                        <div class="section-heading"><h2 class="h6 mb-0">KK Profiling Completion</h2></div>
                        <div class="d-flex flex-column align-items-center justify-content-center h-100 py-3">
                            <div class="display-4 fw-bold text-primary tabular"><?php echo (int) $profiling['pct']; ?>%</div>
                            <div class="text-secondary small text-center"><?php echo (int) $profiling['profiled']; ?> of <?php echo (int) $profiling['active']; ?> verified youth have completed their KK Profiling form</div>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <!-- ============ PREDICTIVE ============ -->
        <div class="tab-pane fade" id="pane-predictive" role="tabpanel">
            <div class="alert alert-light border d-flex gap-2 align-items-start mb-3" role="note">
                <i class="bi bi-info-circle text-primary mt-1"></i>
                <div><strong>What's likely next.</strong> Solid lines are actuals; dashed lines project 3 months ahead using ordinary least-squares (OLS) linear regression over the trailing 12 months. This is transparent statistics, not a black box — treat it as guidance, not certainty.</div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-lg-6">
                    <section class="docket-panel h-100">
                        <div class="section-heading"><h2 class="h6 mb-0">Youth registrations forecast</h2></div>
                        <div class="chart-frame"><canvas id="p_reg"></canvas></div>
                        <p class="small text-secondary mt-2 mb-0" id="fx_reg"></p>
                    </section>
                </div>
                <div class="col-lg-6">
                    <section class="docket-panel h-100">
                        <div class="section-heading"><h2 class="h6 mb-0">Event participation forecast</h2></div>
                        <div class="chart-frame"><canvas id="p_part"></canvas></div>
                        <p class="small text-secondary mt-2 mb-0" id="fx_part"></p>
                    </section>
                </div>
            </div>
            <div class="row g-3">
                <div class="col-12">
                    <section class="docket-panel">
                        <div class="section-heading"><h2 class="h6 mb-0">Forecast summary (next month)</h2></div>
                        <div class="table-responsive">
                            <table class="table align-middle mb-0">
                                <thead><tr><th>Indicator</th><th class="text-end">Latest</th><th class="text-end">Projected</th><th>Trend</th><th class="text-end">Fit (R&sup2;)</th></tr></thead>
                                <tbody>
                                <?php foreach ($forecastRows as $row): ?>
                                    <?php $s = $bundle[$row['series']]; $meta = $s['meta']; $latest = sked_analytics_latest_value($s['history']); ?>
                                    <tr>
                                        <td class="fw-semibold"><?php echo e($row['label']); ?></td>
                                        <td class="text-end tabular"><?php echo (int) $latest; ?></td>
                                        <td class="text-end tabular fw-semibold"><?php echo (int) $meta['next']; ?></td>
                                        <td><span class="<?php echo $row['invert_good'] ? 'metric-good' : ''; ?>"><?php echo sked_analytics_trend_chip($meta); ?></span></td>
                                        <td class="text-end tabular small text-secondary"><?php echo e(number_format((float) $meta['r2'], 2)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="small text-secondary mt-2 mb-0"><i class="bi bi-lightbulb me-1"></i>R&sup2; near 1.00 = a steady, reliable trend; near 0 = irregular month-to-month activity, so read that projection loosely.</p>
                    </section>
                </div>
            </div>
        </div>

        <!-- ============ PRESCRIPTIVE ============ -->
        <div class="tab-pane fade" id="pane-prescriptive" role="tabpanel">
            <div class="alert alert-light border d-flex gap-2 align-items-start mb-3" role="note">
                <i class="bi bi-info-circle text-primary mt-1"></i>
                <div><strong>What to do about it.</strong> Recommended actions generated from the current workload and the forecasts above, most urgent first — advisory only, not a replacement for your own judgement.</div>
            </div>
            <div class="row g-3">
                <div class="col-lg-7">
                    <section class="docket-panel">
                        <div class="section-heading"><h2 class="h6 mb-0">Recommended actions</h2><span class="section-note"><?php echo count($recommendations); ?> item<?php echo count($recommendations) === 1 ? '' : 's'; ?></span></div>
                        <?php foreach ($recommendations as $rec): ?>
                            <div class="reco-item level-<?php echo e((string) $rec['level']); ?>">
                                <span class="reco-icon"><i class="bi <?php echo e((string) $rec['icon']); ?>"></i></span>
                                <div>
                                    <div class="reco-title"><?php echo e((string) $rec['title']); ?></div>
                                    <div class="reco-detail"><?php echo e((string) $rec['detail']); ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </section>
                </div>
                <div class="col-lg-5">
                    <section class="docket-panel h-100">
                        <div class="section-heading"><h2 class="h6 mb-0">Recommended focus areas</h2><span class="section-note"><?php echo count($ranked); ?></span></div>
                        <?php if (empty($ranked)): ?>
                            <p class="small text-secondary mb-0">Not enough profiling, poll, or event-rating data yet to rank focus areas.</p>
                        <?php else: ?>
                            <?php foreach ($ranked as $i => $r): ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-baseline">
                                        <div class="fw-semibold"><span class="text-secondary me-1">#<?php echo $i + 1; ?></span><?php echo e($r['category']); ?></div>
                                        <div class="tabular small fw-bold"><?php echo $r['score']; ?>/100</div>
                                    </div>
                                    <div class="rec-bar mt-1" style="height:7px;border-radius:999px;background:#e5e7f2;overflow:hidden;"><div style="height:100%;width:<?php echo min(100, $r['score']); ?>%;background:linear-gradient(90deg,#4338ca,#818cf8);"></div></div>
                                    <div class="small text-secondary mt-1"><?php echo e(sked_explain_recommendation($r)); ?></div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </section>
                </div>
            </div>
        </div>

        <!-- ============ VOICE OF THE YOUTH (word cloud + sentiment) ============ -->
        <div class="tab-pane fade" id="pane-voice" role="tabpanel">
            <div class="alert alert-light border d-flex gap-2 align-items-start mb-3" role="note">
                <i class="bi bi-info-circle text-primary mt-1"></i>
                <div><strong>What youth are saying.</strong> Mined from Feedback/Concerns messages and KK Profiling "Suggestions" for <?php echo e($scopeNote); ?>. Sentiment is scored with a transparent Filipino+English keyword lexicon (not a black-box model) — a word is positive/negative because it's on a readable list, and negation ("hindi maganda") is accounted for. Treat it as a quick read, not a verdict; the quotes below let you check it yourself.</div>
            </div>

            <?php if ((int) $sentiment['total'] === 0): ?>
                <section class="docket-panel">
                    <div class="text-center text-secondary py-5">
                        <i class="bi bi-chat-square-text fs-1 d-block mb-2"></i>
                        No feedback or KK Suggestions submitted yet for <?php echo e($scopeNote); ?>.
                    </div>
                </section>
            <?php else: ?>
                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <section class="docket-panel">
                            <div class="section-heading"><h2 class="h6 mb-0">Overall sentiment</h2><span class="section-note"><?php echo (int) $sentiment['total']; ?> entries analyzed</span></div>
                            <div class="sentiment-headline tone-<?php echo e((string) $sentiment['overall']); ?> mb-3">
                                <span class="sent-icon"><i class="bi <?php echo e((string) $sentimentTone['icon']); ?>"></i></span>
                                <div>
                                    <div class="sent-title"><?php echo e((string) $sentimentTone['label']); ?></div>
                                    <div class="sent-sub"><?php echo (int) $sentiment['positive']; ?> positive &middot; <?php echo (int) $sentiment['neutral']; ?> neutral &middot; <?php echo (int) $sentiment['negative']; ?> negative</div>
                                </div>
                            </div>
                            <div class="sentiment-bar" role="img" aria-label="Sentiment breakdown: <?php echo e((string) $sentiment['pct']['positive']); ?>% positive, <?php echo e((string) $sentiment['pct']['neutral']); ?>% neutral, <?php echo e((string) $sentiment['pct']['negative']); ?>% negative">
                                <?php if ($sentiment['pct']['positive'] > 0): ?><div class="seg seg-positive" style="width:<?php echo e((string) $sentiment['pct']['positive']); ?>%;"><?php if ($sentiment['pct']['positive'] >= 10) { echo e((string) $sentiment['pct']['positive']) . '%'; } ?></div><?php endif; ?>
                                <?php if ($sentiment['pct']['neutral'] > 0): ?><div class="seg seg-neutral" style="width:<?php echo e((string) $sentiment['pct']['neutral']); ?>%;"><?php if ($sentiment['pct']['neutral'] >= 10) { echo e((string) $sentiment['pct']['neutral']) . '%'; } ?></div><?php endif; ?>
                                <?php if ($sentiment['pct']['negative'] > 0): ?><div class="seg seg-negative" style="width:<?php echo e((string) $sentiment['pct']['negative']); ?>%;"><?php if ($sentiment['pct']['negative'] >= 10) { echo e((string) $sentiment['pct']['negative']) . '%'; } ?></div><?php endif; ?>
                            </div>
                            <div class="sentiment-legend">
                                <span class="lg-key"><span class="lg-dot positive"></span>Positive &middot; <?php echo e((string) $sentiment['pct']['positive']); ?>%</span>
                                <span class="lg-key"><span class="lg-dot neutral"></span>Neutral &middot; <?php echo e((string) $sentiment['pct']['neutral']); ?>%</span>
                                <span class="lg-key"><span class="lg-dot negative"></span>Negative &middot; <?php echo e((string) $sentiment['pct']['negative']); ?>%</span>
                            </div>
                        </section>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-12">
                        <section class="docket-panel">
                            <div class="section-heading"><h2 class="h6 mb-0">Most-mentioned words</h2><span class="section-note">Feedback &amp; KK Suggestions</span></div>
                            <?php if (empty($cloudWords)): ?>
                                <div class="word-cloud-empty">Not enough text yet to surface common words.</div>
                            <?php else: ?>
                                <div class="word-cloud">
                                    <?php foreach ($cloudWords as $w): ?>
                                        <span class="wc-word tier-<?php echo (int) $w['tier']; ?>" tabindex="0" title="<?php echo e((string) $w['word']); ?>: <?php echo (int) $w['count']; ?> mention<?php echo $w['count'] === 1 ? '' : 's'; ?> (<?php echo e((string) $w['pct']); ?>% of words)"><?php echo e((string) $w['word']); ?></span>
                                    <?php endforeach; ?>
                                </div>
                                <p class="small text-secondary mt-1 mb-3"><i class="bi bi-lightbulb me-1"></i>Bigger & darker = mentioned more often. Common connector words (ang, ng, sa, the, is, sana, po, …) are filtered out so only topical words show.</p>
                                <div class="table-responsive">
                                    <table class="table table-sm align-middle mb-0">
                                        <thead><tr><th>Word</th><th class="text-end">Mentions</th><th class="text-end">Share of words</th></tr></thead>
                                        <tbody>
                                        <?php foreach ($cloudWords as $w): ?>
                                            <tr>
                                                <td class="fw-semibold"><?php echo e((string) $w['word']); ?></td>
                                                <td class="text-end tabular"><?php echo (int) $w['count']; ?></td>
                                                <td class="text-end tabular small text-secondary"><?php echo e((string) $w['pct']); ?>%</td>
                                            </tr>
                                        <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            <?php endif; ?>
                        </section>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12">
                        <section class="docket-panel">
                            <div class="section-heading"><h2 class="h6 mb-0">Feedback spotlight</h2><span class="section-note">A spread of <?php echo count($recentFeedback); ?> quotes across sentiment</span></div>
                            <?php if (empty($recentFeedback)): ?>
                                <p class="small text-secondary mb-0">Nothing to show yet.</p>
                            <?php else: ?>
                                <?php foreach ($recentFeedback as $entry): ?>
                                    <div class="feedback-item">
                                        <div class="fi-meta">
                                            <span class="sentiment-badge <?php echo e((string) $entry['label']); ?>"><?php echo e(ucfirst((string) $entry['label'])); ?></span>
                                            <span><?php echo e(sked_feedback_source_label((string) $entry['source'])); ?></span>
                                            <span>&middot;</span>
                                            <span><?php echo e(date('M j, Y', strtotime((string) $entry['created_at']))); ?></span>
                                        </div>
                                        <div class="fi-text">&ldquo;<?php echo e((string) $entry['text']); ?>&rdquo;</div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </section>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
    <script>
        const AX = <?php echo json_encode($chartData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        const P = { civic:'#4338ca', iris:'#818cf8', teal:'#0f766e', amber:'#b45309', coral:'#c02748' };

        if (window.Chart) {
            Chart.defaults.font.family = "'Plus Jakarta Sans', system-ui, sans-serif";
            Chart.defaults.font.size = 12;
            Chart.defaults.color = '#5b5c78';
            Chart.defaults.plugins.legend.labels.usePointStyle = true;
            Chart.defaults.plugins.legend.labels.boxWidth = 8;
            Chart.defaults.plugins.legend.labels.padding = 14;
        }

        const charts = {};
        const done = {};

        function histLabels() { return AX.labels.slice(0, AX.histLen); }
        function hist(series) { return AX.series[series].history.slice(0, AX.histLen); }

        function emptyMsg(el) {
            const ctx = el.getContext('2d');
            ctx.font = '13px "Plus Jakarta Sans", sans-serif'; ctx.fillStyle = '#8a8ba6'; ctx.textAlign = 'center';
            ctx.fillText('No data for this period yet', el.width / 2, el.height / 2);
        }

        function lineTrend(id, labels, datasets) {
            const el = document.getElementById(id);
            if (!el || charts[id]) return;
            charts[id] = new Chart(el, {
                type: 'line',
                data: { labels: labels, datasets: datasets },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(31,32,51,.06)' } }, x: { grid: { display: false } } },
                    plugins: { legend: { position: 'bottom' }, tooltip: { boxPadding: 6 } }
                }
            });
        }

        function doughnut(id, dist) {
            const el = document.getElementById(id);
            if (!el || charts[id]) return;
            if (!(dist.data || []).some(v => v > 0)) { emptyMsg(el); charts[id] = true; return; }
            charts[id] = new Chart(el, {
                type: 'doughnut',
                data: { labels: dist.labels, datasets: [{ data: dist.data, backgroundColor: dist.colors, borderWidth: 2, borderColor: '#fff' }] },
                options: { responsive: true, maintainAspectRatio: false, cutout: '60%', plugins: { legend: { position: 'right' } } }
            });
        }

        function barTrend(id, labels, data, color) {
            const el = document.getElementById(id);
            if (!el || charts[id]) return;
            charts[id] = new Chart(el, {
                type: 'bar',
                data: { labels: labels, datasets: [{ label: 'Events held', data: data, backgroundColor: color }] },
                options: { responsive: true, maintainAspectRatio: false, scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: 'rgba(31,32,51,.06)' } }, x: { grid: { display: false } } }, plugins: { legend: { display: false } } }
            });
        }

        function initTab(tab) {
            if (done[tab]) return;
            done[tab] = true;

            if (tab === 'descriptive') {
                lineTrend('d_reg', histLabels(), [{ label: 'New registrations', data: hist('registrations'), borderColor: P.civic, backgroundColor: 'rgba(67,56,202,.12)', fill: true, tension: .3, pointRadius: 2 }]);
                lineTrend('d_part', histLabels(), [{ label: 'Event sign-ups', data: hist('participations'), borderColor: P.teal, backgroundColor: 'rgba(15,118,110,.12)', fill: true, tension: .3, pointRadius: 2 }]);
                doughnut('d_verif', AX.dist.verification);
                doughnut('d_interest', AX.dist.interest);
                barTrend('d_events', histLabels(), hist('events_held'), P.iris);
                doughnut('d_partmix', AX.dist.participation);
            }
            if (tab === 'predictive') {
                lineTrend('p_reg', AX.labels, [
                    { label: 'Registrations (actual)', data: AX.series.registrations.history, borderColor: P.civic, backgroundColor: 'rgba(67,56,202,.10)', fill: true, tension: .3, pointRadius: 2 },
                    { label: 'Forecast', data: AX.series.registrations.forecast, borderColor: P.civic, borderDash: [6, 5], tension: .2, pointRadius: 0 }
                ]);
                lineTrend('p_part', AX.labels, [
                    { label: 'Participation (actual)', data: AX.series.participations.history, borderColor: P.teal, backgroundColor: 'rgba(15,118,110,.10)', fill: true, tension: .3, pointRadius: 2 },
                    { label: 'Forecast', data: AX.series.participations.forecast, borderColor: P.teal, borderDash: [6, 5], tension: .2, pointRadius: 0 }
                ]);
            }
            Object.values(charts).forEach(c => { if (c && c.resize) c.resize(); });
        }

        document.addEventListener('DOMContentLoaded', function () {
            initTab('descriptive');
            document.querySelectorAll('#analyticsTabs [data-bs-toggle="tab"]').forEach(function (t) {
                t.addEventListener('shown.bs.tab', function (e) { initTab(e.target.getAttribute('data-tab')); });
            });
        });
    </script>
    <?php
}
