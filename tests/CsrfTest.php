<?php
/**
 * Unit tests for the CSRF helper functions defined in includes/auth.php.
 *
 * Functions under test:
 *   csrf_generate()  – creates / returns the session token
 *   csrf_input()     – renders the hidden HTML input
 *   csrf_is_valid()  – pure boolean token-comparison (no exit)
 *   csrf_verify()    – happy-path only (exit() path is handled by csrf_is_valid tests)
 *
 * Session handling:
 *   Each test gets a clean $_SESSION so tokens do not bleed between tests.
 *   PHP CLI sessions are file-based; there are no HTTP headers to worry about.
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversFunction('csrf_generate')]
#[CoversFunction('csrf_input')]
#[CoversFunction('csrf_is_valid')]
#[CoversFunction('csrf_verify')]
final class CsrfTest extends TestCase
{
    // ------------------------------------------------------------------
    // Session lifecycle
    // ------------------------------------------------------------------

    protected function setUp(): void
    {
        // Each test starts with a clean, active session and empty POST.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        session_start();
        $_SESSION = [];
        $_POST    = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST    = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
    }

    // ------------------------------------------------------------------
    // csrf_generate()
    // ------------------------------------------------------------------

    #[Test]
    public function generateCreatesTokenWhenSessionIsEmpty(): void
    {
        $this->assertArrayNotHasKey('csrf_token', $_SESSION);

        $token = csrf_generate();

        $this->assertNotEmpty($token);
        $this->assertSame($token, $_SESSION['csrf_token']);
    }

    #[Test]
    public function generateReturnsExistingTokenWithoutReplacing(): void
    {
        $_SESSION['csrf_token'] = 'already_set_token';

        $token = csrf_generate();

        $this->assertSame('already_set_token', $token, 'Existing token must not be regenerated');
        $this->assertSame('already_set_token', $_SESSION['csrf_token']);
    }

    #[Test]
    public function generateProduces64HexCharacters(): void
    {
        $token = csrf_generate();

        // bin2hex(random_bytes(32)) → 64 lowercase hex chars
        $this->assertSame(64, strlen($token));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
    }

    #[Test]
    public function generateIsIdempotentAcrossMultipleCalls(): void
    {
        $first  = csrf_generate();
        $second = csrf_generate();

        $this->assertSame($first, $second, 'Repeated calls must return the same token');
    }

    // ------------------------------------------------------------------
    // csrf_input()
    // ------------------------------------------------------------------

    #[Test]
    public function inputReturnsAHiddenInputElement(): void
    {
        $html = csrf_input();

        $this->assertStringContainsString('<input', $html);
        $this->assertStringContainsString('type="hidden"', $html);
        $this->assertStringContainsString('name="csrf_token"', $html);
    }

    #[Test]
    public function inputEmbedsSameTokenAsSession(): void
    {
        $token = csrf_generate();
        $html  = csrf_input();

        $this->assertStringContainsString('value="' . $token . '"', $html);
    }

    #[Test]
    public function inputHtmlEncodesSpecialCharactersInToken(): void
    {
        // Simulate a token that contains characters requiring HTML escaping.
        $_SESSION['csrf_token'] = '"<evil>&';

        $html = csrf_input();

        $this->assertStringNotContainsString('"<evil>&', $html, 'Raw special chars must not appear in output');
        $this->assertStringContainsString('&quot;&lt;evil&gt;&amp;', $html);
    }

    // ------------------------------------------------------------------
    // csrf_is_valid()
    // ------------------------------------------------------------------

    #[Test]
    public function isValidReturnsTrueWhenTokensMatch(): void
    {
        $token                  = csrf_generate();
        $_POST['csrf_token']    = $token;

        $this->assertTrue(csrf_is_valid());
    }

    #[Test]
    public function isValidReturnsFalseWhenPostTokenIsMissing(): void
    {
        csrf_generate();
        // $_POST['csrf_token'] not set

        $this->assertFalse(csrf_is_valid());
    }

    #[Test]
    public function isValidReturnsFalseWhenPostTokenIsEmpty(): void
    {
        csrf_generate();
        $_POST['csrf_token'] = '';

        $this->assertFalse(csrf_is_valid());
    }

    #[Test]
    public function isValidReturnsFalseWhenSessionTokenIsMissing(): void
    {
        $_POST['csrf_token'] = 'some_token';
        // $_SESSION['csrf_token'] not set

        $this->assertFalse(csrf_is_valid());
    }

    #[Test]
    public function isValidReturnsFalseWhenTokensDiffer(): void
    {
        $_SESSION['csrf_token'] = 'correct_token_abc123';
        $_POST['csrf_token']    = 'wrong_token_xyz789';

        $this->assertFalse(csrf_is_valid());
    }

    #[Test]
    public function isValidReturnsFalseForPartialTokenMatch(): void
    {
        $token                  = csrf_generate();
        // Submit only the first half of the real token
        $_POST['csrf_token']    = substr($token, 0, 32);

        $this->assertFalse(csrf_is_valid());
    }

    // ------------------------------------------------------------------
    // csrf_verify() — happy path only
    // (The exit() path is indirectly covered by csrf_is_valid() tests above.)
    // ------------------------------------------------------------------

    #[Test]
    public function verifyDoesNotTerminateWhenTokenIsValid(): void
    {
        $token               = csrf_generate();
        $_POST['csrf_token'] = $token;

        // If csrf_verify() incorrectly calls exit(), this test never reaches the assertion.
        csrf_verify();

        $this->assertTrue(true, 'csrf_verify() must not exit when the token is valid');
    }
}
