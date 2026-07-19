<?php
/**
 * Currencies Module Tests
 */

namespace CRM\Tests\Unit\Modules;

use CRM\Tests\DatabaseTestCase;
use CRM\Modules\Currencies;
use CRM\Database;

class CurrenciesTest extends DatabaseTestCase
{
    private Currencies $currencies;
    
    protected function setUp(): void
    {
        parent::setUp();
        $this->currencies = new Currencies();
        
        // Create test currencies
        Database::execute(
            "INSERT INTO currencies (code, name, symbol, symbol_position, decimal_places, is_active, is_default) 
             VALUES ('USD', 'US Dollar', '$', 'before', 2, 1, 1)"
        );
        
        Database::execute(
            "INSERT INTO currencies (code, name, symbol, symbol_position, decimal_places, is_active, is_default) 
             VALUES ('EUR', 'Euro', '€', 'before', 2, 1, 0)"
        );
    }
    
    public function testGetAllCurrencies()
    {
        $result = $this->currencies->getAllCurrencies();
        
        $this->assertIsArray($result);
        $this->assertGreaterThanOrEqual(2, count($result));
        $this->assertEquals('USD', $result[0]['code']); // Default should be first
    }
    
    public function testGetActiveCurrencies()
    {
        // Deactivate EUR
        Database::execute("UPDATE currencies SET is_active = 0 WHERE code = 'EUR'");
        
        $result = $this->currencies->getActiveCurrencies();
        
        $this->assertIsArray($result);
        $this->assertEquals(1, count($result));
        $this->assertEquals('USD', $result[0]['code']);
    }
    
    public function testGetByCode()
    {
        $result = $this->currencies->getByCode('USD');
        
        $this->assertIsArray($result);
        $this->assertEquals('USD', $result['code']);
        $this->assertEquals('US Dollar', $result['name']);
        $this->assertEquals('$', $result['symbol']);
    }
    
    public function testGetByCodeNotFound()
    {
        $result = $this->currencies->getByCode('XYZ');
        
        $this->assertNull($result);
    }
    
    public function testGetDefault()
    {
        $result = $this->currencies->getDefault();
        
        $this->assertIsArray($result);
        $this->assertEquals('USD', $result['code']);
        $this->assertEquals(1, $result['is_default']);
        $this->assertEquals(1, $result['is_active']);
    }
    
    public function testCreateCurrency()
    {
        $id = $this->currencies->create([
            'code' => 'GBP',
            'name' => 'British Pound',
            'symbol' => '£',
            'symbol_position' => 'before',
            'decimal_places' => 2,
            'is_active' => 1,
            'is_default' => 0
        ]);
        
        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        
        $currency = Database::queryOne("SELECT * FROM currencies WHERE id = ?", [$id]);
        $this->assertEquals('GBP', $currency['code']);
        $this->assertEquals('British Pound', $currency['name']);
    }
    
    public function testCreateCurrencySetsDefault()
    {
        // Create new default currency
        $id = $this->currencies->create([
            'code' => 'KES',
            'name' => 'Kenyan Shilling',
            'symbol' => 'KSh',
            'is_default' => 1
        ]);
        
        // Check old default is unset
        $usd = Database::queryOne("SELECT is_default FROM currencies WHERE code = 'USD'");
        $this->assertEquals(0, $usd['is_default']);
        
        // Check new currency is default
        $kes = Database::queryOne("SELECT is_default FROM currencies WHERE id = ?", [$id]);
        $this->assertEquals(1, $kes['is_default']);
    }
    
    public function testUpdateCurrency()
    {
        $eur = Database::queryOne("SELECT id FROM currencies WHERE code = 'EUR'");
        
        $result = $this->currencies->update($eur['id'], [
            'name' => 'Euro Updated',
            'symbol' => '€€'
        ]);
        
        $this->assertTrue($result);
        
        $updated = Database::queryOne("SELECT * FROM currencies WHERE id = ?", [$eur['id']]);
        $this->assertEquals('Euro Updated', $updated['name']);
        $this->assertEquals('€€', $updated['symbol']);
    }
    
    public function testUpdateCurrencySetsDefault()
    {
        $eur = Database::queryOne("SELECT id FROM currencies WHERE code = 'EUR'");
        
        $this->currencies->update($eur['id'], ['is_default' => 1]);
        
        // Check EUR is now default
        $eur = Database::queryOne("SELECT is_default FROM currencies WHERE code = 'EUR'");
        $this->assertEquals(1, $eur['is_default']);
        
        // Check USD is no longer default
        $usd = Database::queryOne("SELECT is_default FROM currencies WHERE code = 'USD'");
        $this->assertEquals(0, $usd['is_default']);
    }
    
    public function testDeleteCurrency()
    {
        $eur = Database::queryOne("SELECT id FROM currencies WHERE code = 'EUR'");
        
        $result = $this->currencies->delete($eur['id']);
        
        $this->assertTrue($result);
        
        $deleted = Database::queryOne("SELECT * FROM currencies WHERE id = ?", [$eur['id']]);
        $this->assertNull($deleted);
    }
    
    public function testDeleteDefaultCurrencyThrowsException()
    {
        $usd = Database::queryOne("SELECT id FROM currencies WHERE code = 'USD'");
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Cannot delete the default currency");
        
        $this->currencies->delete($usd['id']);
    }
    
    public function testFormatAmountWithDefaultCurrency()
    {
        $result = $this->currencies->formatAmount(1234.56);
        
        $this->assertEquals('$1,234.56', $result);
    }
    
    public function testFormatAmountWithSpecificCurrency()
    {
        $result = $this->currencies->formatAmount(1234.56, 'EUR');
        
        $this->assertEquals('€1,234.56', $result);
    }
    
    public function testFormatAmountWithSymbolAfter()
    {
        // Create currency with symbol after
        $id = $this->currencies->create([
            'code' => 'CHF',
            'name' => 'Swiss Franc',
            'symbol' => 'CHF',
            'symbol_position' => 'after',
            'decimal_places' => 2
        ]);
        
        $result = $this->currencies->formatAmount(1234.56, 'CHF');
        
        $this->assertEquals('1,234.56 CHF', $result);
    }
    
    public function testFormatAmountWithCustomSeparators()
    {
        // Create currency with custom separators
        $id = $this->currencies->create([
            'code' => 'CUSTOM',
            'name' => 'Custom Currency',
            'symbol' => 'C',
            'thousands_separator' => '.',
            'decimal_separator' => ',',
            'decimal_places' => 2
        ]);
        
        $result = $this->currencies->formatAmount(1234.56, 'CUSTOM');
        
        $this->assertEquals('C1.234,56', $result);
    }
    
    public function testFormatAmountWithZeroDecimals()
    {
        // Create currency with 0 decimal places
        $id = $this->currencies->create([
            'code' => 'JPY',
            'name' => 'Japanese Yen',
            'symbol' => '¥',
            'decimal_places' => 0
        ]);
        
        $result = $this->currencies->formatAmount(1234.56, 'JPY');
        
        $this->assertEquals('¥1,235', $result);
    }
    
    public function testFormatAmountWithInvalidCurrency()
    {
        $result = $this->currencies->formatAmount(1234.56, 'INVALID');
        
        // Should fallback to USD formatting
        $this->assertEquals('$1,234.56', $result);
    }
    
    public function testGetCurrencyOptions()
    {
        $options = $this->currencies->getCurrencyOptions();
        
        $this->assertIsArray($options);
        $this->assertArrayHasKey('USD', $options);
        $this->assertArrayHasKey('EUR', $options);
        $this->assertStringContainsString('US Dollar', $options['USD']);
        $this->assertStringContainsString('$', $options['USD']);
    }
}
