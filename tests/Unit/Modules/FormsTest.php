<?php
/**
 * Forms Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Database;
use CRM\Modules\Forms;
use CRM\Tests\DatabaseTestCase;

class FormsTest extends DatabaseTestCase
{
    private Forms $forms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->forms = new Forms();
    }

    public function testDeleteFormRemovesStoredSubmissionsForUuidAndDefinitionId(): void
    {
        $formId = $this->forms->create([
            'name' => 'Website Lead Form',
            'fields' => [
                ['name' => 'email', 'type' => 'email'],
            ],
        ]);

        $form = $this->forms->getById($formId);
        $this->assertNotNull($form);

        Database::execute(
            "INSERT INTO form_submissions (workspace_id, visitor_id, form_id, form_definition_id, form_data, page_path)
             VALUES (?, ?, ?, ?, ?, ?)",
            [1, 'visitor-1', (string) $form['uuid'], $formId, json_encode(['email' => 'one@example.com']), '/landing']
        );
        Database::execute(
            "INSERT INTO form_submissions (workspace_id, visitor_id, form_id, form_definition_id, form_data, page_path)
             VALUES (?, ?, ?, ?, ?, ?)",
            [1, 'visitor-2', 'legacy-form-id', $formId, json_encode(['email' => 'two@example.com']), '/landing']
        );

        $this->assertSame(2, $this->forms->getSubmissionCount($formId));

        $deleted = $this->forms->delete($formId);

        $this->assertTrue($deleted);
        $this->assertNull(Database::queryOne("SELECT id FROM forms WHERE id = ?", [$formId]));
        $this->assertSame(0, (int) (Database::queryOne(
            "SELECT COUNT(*) AS c
             FROM form_submissions
             WHERE form_id = ?
                OR form_definition_id = ?",
            [(string) $form['uuid'], $formId]
        )['c'] ?? 0));
    }

    public function testRedirectUrlRejectsUnsafeSchemes(): void
    {
        $formId = $this->forms->create([
            'name' => 'Unsafe Redirect Form',
            'fields' => [
                ['name' => 'email', 'type' => 'email'],
            ],
            'redirect_url' => 'javascript:alert(1)',
        ]);

        $form = $this->forms->getById($formId);
        $this->assertNotNull($form);
        $this->assertEmpty($form['redirect_url']);

        $this->forms->update($formId, ['redirect_url' => 'https://example.com/thanks']);

        $form = $this->forms->getById($formId);
        $this->assertSame('https://example.com/thanks', $form['redirect_url']);
    }

    public function testUpdateRejectsBlankFormName(): void
    {
        $formId = $this->forms->create([
            'name' => 'Lead Form',
            'fields' => [
                ['name' => 'email', 'type' => 'email'],
            ],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Form name is required');

        $this->forms->update($formId, ['name' => '   ']);
    }
}
