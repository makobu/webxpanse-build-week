<?php
$dealStageTabItems = [
    'all' => ['label' => 'All Deals', 'href' => 'deals.php', 'icon' => 'fa-briefcase'],
    'proposal' => ['label' => 'Proposal', 'href' => 'deals_proposal.php', 'icon' => 'fa-file-signature'],
    'negotiation' => ['label' => 'Negotiation', 'href' => 'deals_negotiation.php', 'icon' => 'fa-handshake'],
    'won' => ['label' => 'Won', 'href' => 'deals_won.php', 'icon' => 'fa-trophy'],
    'lost' => ['label' => 'Lost', 'href' => 'deals_lost.php', 'icon' => 'fa-ban'],
];
$activeDealPage = $activeDealPage ?? 'all';
?>
<div class="deal-stage-tabs" aria-label="Deal stage navigation">
    <?php foreach ($dealStageTabItems as $tabKey => $tab): ?>
        <a
            href="<?php echo htmlspecialchars($tab['href']); ?>"
            class="deal-stage-tab <?php echo $activeDealPage === $tabKey ? 'active' : ''; ?>"
        >
            <i class="fas <?php echo htmlspecialchars($tab['icon']); ?>"></i>
            <?php echo htmlspecialchars($tab['label']); ?>
        </a>
    <?php endforeach; ?>
</div>
