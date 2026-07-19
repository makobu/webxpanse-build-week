<?php

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\IdeaValidationContext;
use CRM\Modules\UserStrategyProfile;
use CRM\Modules\UserStrategySnapshot;
use CRM\Tests\DatabaseTestCase;

class UserStrategySnapshotTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Database::execute('DELETE FROM user_strategy_snapshots');
        Database::execute('DELETE FROM idea_validation_context');
        Database::execute('DELETE FROM user_strategy_profiles');
    }

    public function testSyncCreatesDedupesAndSupersedesStrategySnapshots(): void
    {
        $profile = new UserStrategyProfile();
        $idea = new IdeaValidationContext();
        $snapshots = new UserStrategySnapshot();

        $profile->save(21, [
            'target_market_focus' => 'Founder-led SaaS',
            'ideal_customer_profile' => 'Revenue owners',
            'offer_angle' => 'Reliable pipeline follow-through',
            'market_view' => 'Small SaaS teams want proof before automation.',
            'strategy_hypothesis' => 'Live audits will convert better than broad nurture.',
            'sales_motion' => 'Audit-led consultative selling',
        ]);
        $idea->save(21, [
            'value_proposition' => 'AI coach for stuck pipeline work',
            'target_market' => 'Founder-led SaaS teams',
            'pain_points' => 'Dropped handoffs',
            'competitors' => 'Spreadsheets, generic CRMs',
            'differentiator' => 'Personal strategy coaching tied to outcomes',
        ]);

        $first = $snapshots->syncForUser(1, 21);
        $this->assertNotNull($first);
        $this->assertSame(1, (int) ($first['version'] ?? 0));
        $this->assertSame('active', (string) ($first['status'] ?? ''));

        Database::execute('UPDATE user_strategy_snapshots SET source_hash = ? WHERE id = ?', [str_repeat('0', 64), (int) ($first['id'] ?? 0)]);
        $again = $snapshots->syncForUser(1, 21);
        $this->assertSame((int) ($first['id'] ?? 0), (int) ($again['id'] ?? 0));
        $this->assertNotSame(str_repeat('0', 64), (string) ($again['source_hash'] ?? ''));
        $this->assertSame(1, (int) (Database::queryOne('SELECT COUNT(*) AS count FROM user_strategy_snapshots WHERE workspace_id = 1 AND user_id = 21')['count'] ?? 0));

        $profile->save(21, array_merge($profile->get(21) ?: [], [
            'strategy_hypothesis' => 'Proof-first pilots will convert better than live audits.',
        ]));

        $second = $snapshots->syncForUser(1, 21);
        $this->assertNotNull($second);
        $this->assertSame(2, (int) ($second['version'] ?? 0));
        $this->assertNotSame((int) ($first['id'] ?? 0), (int) ($second['id'] ?? 0));

        $old = Database::queryOne('SELECT status, ended_at FROM user_strategy_snapshots WHERE id = ?', [(int) ($first['id'] ?? 0)]);
        $this->assertSame('superseded', (string) ($old['status'] ?? ''));
        $this->assertNotEmpty($old['ended_at'] ?? null);
    }

    public function testPersonalBriefRequirementsAcceptExistingStrategyFieldsAsFallbacks(): void
    {
        $snapshots = new UserStrategySnapshot();
        $missing = $snapshots->missingPersonalBriefRequirementsFromData([
            'target_market_focus' => 'Agencies',
            'ideal_customer_profile' => 'Agency owners',
            'sales_motion' => 'Consultative selling',
            'competitors' => 'Spreadsheets',
        ]);

        $this->assertSame([], $missing);
    }
}
