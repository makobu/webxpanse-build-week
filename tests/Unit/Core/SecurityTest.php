<?php
/**
 * Security Tests
 */

namespace CRM\Tests\Unit\Core;

use CRM\Tests\TestCase;
use CRM\Security;
use CRM\Session;

class SecurityTest extends TestCase
{
    public function testSanitizeInputString(): void
    {
        $input = '<script>alert("xss")</script>Hello';
        $result = Security::sanitizeInput($input, 'string');
        $this->assertStringNotContainsString('<script>', $result);
        $this->assertStringContainsString('Hello', $result);
    }
    
    public function testSanitizeInputEmail(): void
    {
        $valid = Security::sanitizeInput('test@example.com', 'email');
        $this->assertEquals('test@example.com', $valid);
        
        $invalid = Security::sanitizeInput('not-an-email', 'email');
        $this->assertNull($invalid);
    }
    
    public function testSanitizeInputInt(): void
    {
        $result = Security::sanitizeInput('123abc', 'int');
        $this->assertEquals('123', $result);
    }
    
    public function testValidateEmail(): void
    {
        $this->assertTrue(Security::validateEmail('test@example.com'));
        $this->assertFalse(Security::validateEmail('invalid-email'));
    }
    
    public function testValidatePhone(): void
    {
        $this->assertTrue(Security::validatePhone('+1234567890'));
        $this->assertTrue(Security::validatePhone('1234567890'));
        $this->assertFalse(Security::validatePhone('abc'));
    }
    
    public function testGetCsrfToken(): void
    {
        Session::start();
        $token = Security::getCsrfToken();
        $this->assertIsString($token);
        $this->assertEquals(64, strlen($token)); // 32 bytes = 64 hex chars
    }
    
    public function testValidateCSRF(): void
    {
        Session::start();
        $token = Security::getCsrfToken();
        $this->assertTrue(Security::validateCSRF($token));
        $this->assertFalse(Security::validateCSRF('invalid-token'));
    }
    
    public function testEncryptDecrypt(): void
    {
        $key = 'test-key-32-characters-long!!';
        $data = 'sensitive information';
        
        $encrypted = Security::encryptSensitiveData($data, $key);
        $this->assertIsString($encrypted);
        $this->assertNotEquals($data, $encrypted);
        
        $decrypted = Security::decryptSensitiveData($encrypted, $key);
        $this->assertEquals($data, $decrypted);
    }
    
    public function testGenerateRandomString(): void
    {
        $str1 = Security::generateRandomString(16);
        $str2 = Security::generateRandomString(16);
        
        $this->assertEquals(16, strlen($str1));
        $this->assertNotEquals($str1, $str2);
    }

    public function testSanitizeRedirectUrlAllowsHttpAndHttps(): void
    {
        $this->assertSame('https://example.com/path', Security::sanitizeRedirectUrl('https://example.com/path'));
        $this->assertSame('http://example.com/path', Security::sanitizeRedirectUrl('http://example.com/path'));
    }

    public function testSanitizeRedirectUrlRejectsUnsafeTargets(): void
    {
        $this->assertSame('/', Security::sanitizeRedirectUrl('javascript:alert(1)'));
        $this->assertSame('/', Security::sanitizeRedirectUrl("//example.com\nSet-Cookie: bad=1"));
        $this->assertSame('/', Security::sanitizeRedirectUrl('//example.com'));
    }

    public function testSanitizeHeaderFilenameRemovesHeaderUnsafeCharacters(): void
    {
        $this->assertSame('invoice_Q1.pdf', Security::sanitizeHeaderFilename("..\\invoice\"\r\nQ1.pdf"));
        $this->assertSame('file', Security::sanitizeHeaderFilename("\r\n"));
    }
}
