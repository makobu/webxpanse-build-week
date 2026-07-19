<?php

namespace CRM\Tests\Unit\Services;

use CRM\Modules\Forms;
use CRM\Services\FormStudioService;
use CRM\Tests\DatabaseTestCase;
use RuntimeException;

final class FormStudioServiceTest extends DatabaseTestCase
{
    public function testDraftSavePublishAndPublicSnapshotRemainSeparated(): void
    {
        $studio = new FormStudioService();
        $forms = new Forms();
        $id = $studio->create('Qualified leads', 'contact');

        $created = $studio->editorData($id);
        $this->assertSame(1, $created['revision']);
        $this->assertFalse($created['is_published']);
        $this->assertNull($studio->publicDocument($created['form']));
        $this->assertSame('Qualified leads', $studio->publicDocument($created['form'], $created['preview_token'])['content']['title']);

        $document = $created['document'];
        $document['content']['title'] = 'Qualified leads v1';
        $saved = $studio->save($id, $document, [], 1);
        $this->assertSame(2, $saved['revision']);

        $published = $studio->publish($id);
        $this->assertTrue($published['is_published']);
        $liveForm = $forms->getById($id);
        $this->assertSame('Qualified leads v1', $studio->publicDocument($liveForm)['content']['title']);

        $draft = $published['document'];
        $draft['content']['title'] = 'Unpublished v2';
        $studio->save($id, $draft, [], 2);
        $latestForm = $forms->getById($id);
        $this->assertSame('Qualified leads v1', $studio->publicDocument($latestForm)['content']['title']);
        $this->assertSame('Unpublished v2', $studio->publicDocument($latestForm, (string) $latestForm['preview_token'])['content']['title']);
    }

    public function testOptimisticRevisionRejectsAStaleEditor(): void
    {
        $studio = new FormStudioService();
        $id = $studio->create('Revision test', 'contact');
        $editor = $studio->editorData($id);

        $studio->save($id, $editor['document'], [], 1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('changed in another session');
        $studio->save($id, $editor['document'], [], 1);
    }
}
