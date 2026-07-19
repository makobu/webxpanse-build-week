<?php
$allDeals = $allDeals ?? [];
$dealEmptyStateCopy = $dealEmptyStateCopy ?? 'Create a sample opportunity so the team can see how the next steps map into the pipeline.';
?>
<div class="table-card">
    <?php if (empty($allDeals)): ?>
        <div class="empty-state">
            <p>No deals found.</p>
            <p style="color:#64748b;"><?php echo htmlspecialchars($dealEmptyStateCopy); ?></p>
            <a href="deal_create.php">
                Create your first deal →
            </a>
        </div>
    <?php else: ?>
        <div class="table-responsive">
        <table class="premium-table">
            <thead>
                <tr>
                    <th>Deal</th>
                    <th>Contact</th>
                    <th>Stage</th>
                    <th style="text-align: right;">Value</th>
                    <th style="text-align: center;">Probability</th>
                    <th>Assigned To</th>
                    <th>Expected Close</th>
                    <th style="text-align: right;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allDeals as $deal): ?>
                    <tr>
                        <td>
                            <a href="deal_view.php?id=<?php echo $deal['id']; ?>" style="color: #0f172a; text-decoration: none; font-weight: 500;">
                                <?php echo htmlspecialchars($deal['title']); ?>
                            </a>
                        </td>
                        <td>
                            <?php if ($deal['contact_id']): ?>
                                <a href="contact_view.php?id=<?php echo $deal['contact_id']; ?>" style="color: #667eea; text-decoration: none;">
                                    <?php echo htmlspecialchars(($deal['contact_first_name'] ?? '') . ' ' . ($deal['contact_last_name'] ?? '')); ?>
                                </a>
                            <?php else: ?>
                                <span style="color: #64748b;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <span class="badge badge-default" data-list-stage-badge="<?php echo (int) $deal['id']; ?>">
                                <?php echo htmlspecialchars($stageLabels[(string) $deal['stage']] ?? str_replace('_', ' ', (string) $deal['stage'])); ?>
                            </span>
                        </td>
                        <td style="text-align: right; font-weight: 600; color: #0f172a;">
                            <?php echo htmlspecialchars($renderDealValue($deal)); ?>
                        </td>
                        <td style="text-align: center;">
                            <?php if ($deal['probability'] > 0): ?>
                                <div style="display: inline-block; width: 60px; background: rgba(0, 0, 0, 0.1); height: 8px; border-radius: 4px; overflow: hidden;">
                                    <div style="background: #667eea; height: 100%; width: <?php echo $deal['probability']; ?>%;"></div>
                                </div>
                                <div style="color: #64748b; font-size: 0.6875rem; margin-top: 4px;">
                                    <?php echo $deal['probability']; ?>%
                                </div>
                            <?php else: ?>
                                <span style="color: #64748b;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php echo htmlspecialchars($deal['assigned_to_email'] ?? 'Unassigned'); ?>
                        </td>
                        <td>
                            <?php echo $deal['expected_close_date'] ? date('M d, Y', strtotime($deal['expected_close_date'])) : '-'; ?>
                        </td>
                        <td style="text-align: right;">
                            <?php $destinations = $allowedDestinations((string) $deal['stage']); ?>
                            <select class="deal-transition-select"
                                    data-transition-select
                                    data-deal-id="<?php echo (int) $deal['id']; ?>"
                                    data-current-stage="<?php echo htmlspecialchars((string) $deal['stage']); ?>"
                                    data-deal-title="<?php echo htmlspecialchars((string) $deal['title']); ?>"
                                    style="margin-right:0.5rem;">
                                <option value="">Move to...</option>
                                <?php foreach ($destinations as $destination): ?>
                                    <option value="<?php echo htmlspecialchars($destination); ?>">
                                        <?php echo htmlspecialchars($stageLabels[$destination] ?? ucfirst(str_replace('_', ' ', $destination))); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button"
                                    class="deal-transition-button"
                                    data-transition-button
                                    data-deal-id="<?php echo (int) $deal['id']; ?>"
                                    data-current-stage="<?php echo htmlspecialchars((string) $deal['stage']); ?>"
                                    data-deal-title="<?php echo htmlspecialchars((string) $deal['title']); ?>"
                                    style="margin-right:0.5rem;">
                                Move
                            </button>
                            <a href="deal_edit.php?id=<?php echo $deal['id']; ?>" style="color: #64748b; text-decoration: none; font-size: 0.875rem; margin-right: 0.75rem;">Edit</a>
                            <a href="deal_delete.php?id=<?php echo $deal['id']; ?>" onclick="return confirm('Are you sure you want to delete this deal?');" style="color: #ef4444; text-decoration: none; font-size: 0.875rem;">Delete</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    <?php endif; ?>
</div>
