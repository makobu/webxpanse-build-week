<?php
/**
 * Custom Fields Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\CustomFields;
use CRM\Modules\Contacts;
use CRM\Database;

class CustomFieldsTest extends DatabaseTestCase
{
    private CustomFields $customFields;
    private Contacts $contacts;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->customFields = new CustomFields();
        $this->contacts = new Contacts();
    }
    
    public function testCreateCustomField(): void
    {
        $data = [
            'field_name' => 'Favorite Color',
            'field_type' => 'select',
            'field_options' => ['red', 'blue', 'green'],
            'module' => 'contacts',
            'is_required' => false
        ];
        
        $fieldId = $this->customFields->create($data);
        $this->assertIsInt($fieldId);
        $this->assertGreaterThan(0, $fieldId);
        
        // Clean up
        $this->customFields->delete($fieldId);
    }
    
    public function testCreateCustomFieldInvalidType(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid field type');
        
        $data = [
            'field_name' => 'Test',
            'field_type' => 'invalid_type'
        ];
        
        $this->customFields->create($data);
    }

    public function testCreateCustomFieldRequiresName(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Custom field name is required');

        $this->customFields->create([
            'field_name' => '   ',
            'field_type' => 'text',
            'module' => 'contacts',
        ]);
    }

    public function testCreateCustomFieldRejectsUnknownModule(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid custom field module');

        $this->customFields->create([
            'field_name' => 'Internal Code',
            'field_type' => 'text',
            'module' => 'users',
        ]);
    }
    
    public function testGetCustomFieldsByModule(): void
    {
        // Create fields
        $field1 = $this->customFields->create([
            'field_name' => 'Field 1',
            'field_type' => 'text',
            'module' => 'contacts'
        ]);
        
        $field2 = $this->customFields->create([
            'field_name' => 'Field 2',
            'field_type' => 'number',
            'module' => 'contacts'
        ]);
        
        $fields = $this->customFields->getByModule('contacts');
        $this->assertGreaterThanOrEqual(2, count($fields));
        
        // Clean up
        $this->customFields->delete($field1);
        $this->customFields->delete($field2);
    }
    
    public function testSetAndGetContactValue(): void
    {
        // Create contact
        $contact = $this->contacts->create([
            'first_name' => 'Test',
            'email' => 'test@example.com'
        ]);
        
        // Create custom field
        $fieldId = $this->customFields->create([
            'field_name' => 'Test Field',
            'field_type' => 'text',
            'module' => 'contacts'
        ]);
        
        // Set value
        $set = $this->customFields->setContactValue($contact['id'], $fieldId, 'Test Value');
        $this->assertTrue($set);
        
        // Get value
        $value = $this->customFields->getContactValue($contact['id'], $fieldId);
        $this->assertEquals('Test Value', $value);
        
        // Get all values
        $values = $this->customFields->getContactValues($contact['id']);
        $this->assertGreaterThan(0, count($values));
        
        // Clean up
        $this->contacts->delete($contact['id']);
        $this->customFields->delete($fieldId);
    }

    public function testSetContactValueRejectsInvalidSelectOption(): void
    {
        $contact = $this->contacts->create([
            'first_name' => 'Select',
            'email' => 'select-custom-field@example.com',
        ]);
        $fieldId = $this->customFields->create([
            'field_name' => 'department',
            'field_type' => 'select',
            'field_options' => ['Sales', 'Support'],
        ]);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('not an allowed option');
            $this->customFields->setContactValue($contact['id'], $fieldId, 'Finance');
        } finally {
            $this->contacts->delete($contact['id']);
            $this->customFields->delete($fieldId);
        }
    }

    public function testSetContactValueRejectsBlankRequiredValue(): void
    {
        $contact = $this->contacts->create([
            'first_name' => 'Required',
            'email' => 'required-custom-field@example.com',
        ]);
        $fieldId = $this->customFields->create([
            'field_name' => 'customer_code',
            'field_type' => 'text',
            'is_required' => true,
        ]);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->customFields->setContactValue($contact['id'], $fieldId, '');
        } finally {
            $this->contacts->delete($contact['id']);
            $this->customFields->delete($fieldId);
        }
    }
    
    public function testUpdateCustomField(): void
    {
        $fieldId = $this->customFields->create([
            'field_name' => 'Original',
            'field_type' => 'text',
            'module' => 'contacts'
        ]);
        
        $updated = $this->customFields->update($fieldId, [
            'field_name' => 'Updated',
            'is_required' => true
        ]);
        
        $this->assertTrue($updated);
        
        $field = $this->customFields->getById($fieldId);
        $this->assertEquals('Updated', $field['field_name']);
        $this->assertEquals(1, $field['is_required']);
        
        // Clean up
        $this->customFields->delete($fieldId);
    }

    public function testUpdateCustomFieldRejectsBlankName(): void
    {
        $fieldId = $this->customFields->create([
            'field_name' => 'Original',
            'field_type' => 'text',
            'module' => 'contacts',
        ]);

        try {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage('Custom field name is required');
            $this->customFields->update($fieldId, ['field_name' => '']);
        } finally {
            $this->customFields->delete($fieldId);
        }
    }

    public function testGetLabelUsesStoredMetadataLabel(): void
    {
        $field = [
            'field_name' => 'department',
            'field_options' => json_encode([
                '_label' => 'Department',
                'options' => ['Sales', 'Support'],
            ]),
        ];

        $this->assertSame('Department', $this->customFields->getLabel($field));
    }

    public function testGetSelectableOptionsExcludesLabelMetadata(): void
    {
        $field = [
            'field_name' => 'department',
            'field_options' => json_encode([
                '_label' => 'Department',
                'Sales',
                'Support',
            ]),
        ];

        $this->assertSame(['Sales', 'Support'], array_values($this->customFields->getSelectableOptions($field)));
    }

    public function testGetSelectableOptionsNormalizesNestedOptionsPayload(): void
    {
        $field = [
            'field_name' => 'department',
            'field_options' => json_encode([
                '_label' => 'Department',
                'options' => ['Sales', 'Support'],
            ]),
        ];

        $this->assertSame(['Sales', 'Support'], $this->customFields->getSelectableOptions($field));
    }

    public function testUpdateCustomFieldPersistsLabelMetadata(): void
    {
        $fieldId = $this->customFields->create([
            'field_name' => 'department',
            'field_type' => 'select',
            'field_options' => ['Sales', 'Support'],
            'module' => 'contacts',
        ]);

        $this->customFields->update($fieldId, [
            'field_options' => [
                '_label' => 'Department',
                'Sales',
                'Support',
            ],
            'display_order' => 3,
        ]);

        $field = $this->customFields->getById($fieldId);
        $this->assertSame('Department', $this->customFields->getLabel($field));
        $this->assertSame(['Sales', 'Support'], $this->customFields->getSelectableOptions($field));

        $this->customFields->delete($fieldId);
    }

    public function testUpdateCustomFieldCanClearSelectOptions(): void
    {
        $fieldId = $this->customFields->create([
            'field_name' => 'department',
            'field_type' => 'select',
            'field_options' => ['Sales', 'Support'],
            'module' => 'contacts',
        ]);

        $this->customFields->update($fieldId, [
            'field_options' => ['_label' => 'Department'],
        ]);

        $field = $this->customFields->getById($fieldId);
        $this->assertSame('Department', $this->customFields->getLabel($field));
        $this->assertSame([], $this->customFields->getSelectableOptions($field));

        $this->customFields->delete($fieldId);
    }

    public function testDeleteUnusedCustomFieldRemovesDefinition(): void
    {
        $fieldId = $this->customFields->create([
            'field_name' => 'unused_field',
            'field_type' => 'text',
            'module' => 'contacts',
        ]);

        $this->assertSame(0, $this->customFields->getUsageCount($fieldId));
        $this->assertTrue($this->customFields->delete($fieldId));
        $this->assertNull($this->customFields->getById($fieldId));
    }

    public function testDeleteCustomFieldRemovesStoredContactData(): void
    {
        $contact = $this->contacts->create([
            'first_name' => 'Delete',
            'email' => 'delete-field@example.com'
        ]);

        $fieldId = $this->customFields->create([
            'field_name' => 'Favorite Team',
            'field_type' => 'text',
            'module' => 'contacts'
        ]);

        $this->customFields->setContactValue($contact['id'], $fieldId, 'Team Blue');
        $this->assertNotNull(Database::queryOne(
            "SELECT field_value FROM contact_custom_data WHERE contact_id = ? AND field_id = ?",
            [$contact['id'], $fieldId]
        ));

        $this->customFields->delete($fieldId);

        $this->assertNull(Database::queryOne("SELECT id FROM custom_fields WHERE id = ?", [$fieldId]));
        $this->assertNull(Database::queryOne(
            "SELECT field_value FROM contact_custom_data WHERE contact_id = ? AND field_id = ?",
            [$contact['id'], $fieldId]
        ));

        $this->contacts->delete($contact['id']);
    }
}
