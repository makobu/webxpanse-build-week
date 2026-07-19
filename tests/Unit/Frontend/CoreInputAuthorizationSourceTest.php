<?php

namespace CRM\Tests\Unit\Frontend;

use PHPUnit\Framework\TestCase;

class CoreInputAuthorizationSourceTest extends TestCase
{
    public function testContactsApiEnforcesRecordVisibilityAndServerOwnedCreator(): void
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../api/contacts.php');

        $this->assertGreaterThanOrEqual(4, substr_count($source, 'contactsApiCanAccessRecord('));
        $this->assertStringContainsString("\$data['created_by'] = (int) (\$user['id'] ?? 0);", $source);
        $this->assertStringContainsString('The contact request could not be completed.', $source);
    }

    public function testInvoiceEndpointsProtectSensitiveStates(): void
    {
        $createApi = (string) file_get_contents(__DIR__ . '/../../../api/invoices.php');
        $statusApi = (string) file_get_contents(__DIR__ . '/../../../api/invoice_status.php');

        $this->assertStringNotContainsString("'status' => \$_POST['status']", $createApi);
        $this->assertStringContainsString("'invoices.finalize'", $statusApi);
        $this->assertStringContainsString("'invoices.mark_paid'", $statusApi);
    }

    public function testDocumentHandlersUsePrivateStorageContainment(): void
    {
        $module = (string) file_get_contents(__DIR__ . '/../../../modules/Documents.php');
        $download = (string) file_get_contents(__DIR__ . '/../../../public/document_download.php');
        $preview = (string) file_get_contents(__DIR__ . '/../../../public/document_preview.php');

        $this->assertStringContainsString("'crm-private'", $module);
        $this->assertStringContainsString('resolveManagedFilePath', $download);
        $this->assertStringContainsString('resolveManagedFilePath', $preview);
        $this->assertStringNotContainsString("../uploads/documents/", $download);
    }

    public function testTaskTargetAndListScopesCannotBeBypassed(): void
    {
        $tasksModule = (string) file_get_contents(__DIR__ . '/../../../modules/Tasks.php');
        $taskList = (string) file_get_contents(__DIR__ . '/../../../public/tasks.php');

        $this->assertStringContainsString("assertSameWorkspace('targets'", $tasksModule);
        $this->assertStringContainsString('tg.workspace_id = t.workspace_id', $tasksModule);
        $this->assertStringContainsString("\$assignedToParamProvided && \$assignedToValue === ''", $taskList);
    }

    public function testDealAndNoteAssignmentsStayInsideWorkspace(): void
    {
        $deals = (string) file_get_contents(__DIR__ . '/../../../modules/Deals.php');
        $notes = (string) file_get_contents(__DIR__ . '/../../../modules/Notes.php');

        $this->assertStringContainsString('assertAssignableUser', $deals);
        $this->assertStringContainsString("assertSameWorkspace('products'", $deals);
        $this->assertStringContainsString('wm.membership_status', $notes);
        $this->assertStringContainsString('wm.workspace_id', $notes);
    }
}
