<?php
/**
 * Integration tests for:
 *   registration_finalize_payment()   – atomic DB finalization (db_helper.php)
 *   success_get_registration_with_kids() – data loader (db_helper.php)
 *   payment_finalize_and_notify()     – coordinator that wraps the above two
 *                                       plus the mailer (mailer.php)
 *
 * Prerequisites (run once before the suite):
 *   mysql -u root -proot crossp11_db1 < migrations/add_confirmation_email_sent.sql
 *
 * The bootstrap opens a real MySQL connection so that SELECT … FOR UPDATE
 * locking is exercised.  APP_ENV is set to 'test' in bootstrap.php, so
 * send_registration_confirmation_email() is never actually invoked by
 * payment_finalize_and_notify() during testing.
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversFunction('registration_finalize_payment')]
#[CoversFunction('success_get_registration_with_kids')]
#[CoversFunction('payment_finalize_and_notify')]
final class RegistrationFinalizePaymentTest extends TestCase
{
    /** @var list<int> IDs to delete in tearDown */
    private array $cleanupIds = [];

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    private function pdo(): PDO
    {
        return $GLOBALS['pdo'];
    }

    private function prefix(): string
    {
        return db_prefix();
    }

    /**
     * Insert a minimal registration row and queue it for cleanup.
     *
     * @param array<string,mixed> $overrides  Column overrides (e.g. ['status' => 'paid'])
     */
    private function insertRegistration(array $overrides = []): int
    {
        $db   = $this->prefix();
        $data = array_merge([
            'parent_first_name'       => 'Test',
            'parent_last_name'        => 'Parent',
            'email'                   => 'test@example.com',
            'status'                  => 'draft',
            'confirmation_email_sent' => 0,
            'total_amount_cents'      => 5000,
        ], $overrides);

        $this->pdo()->prepare(
            "INSERT INTO {$db}registrations
             (parent_first_name, parent_last_name, email, status, confirmation_email_sent, total_amount_cents)
             VALUES (?, ?, ?, ?, ?, ?)"
        )->execute([
            $data['parent_first_name'],
            $data['parent_last_name'],
            $data['email'],
            $data['status'],
            $data['confirmation_email_sent'],
            $data['total_amount_cents'],
        ]);

        $id = (int) $this->pdo()->lastInsertId();
        $this->cleanupIds[] = $id;
        return $id;
    }

    /** Fetch a single registration row for assertions. */
    private function fetchRow(int $id): ?array
    {
        $db   = $this->prefix();
        $stmt = $this->pdo()->prepare(
            "SELECT * FROM {$db}registrations WHERE id = ?"
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    protected function tearDown(): void
    {
        if (empty($this->cleanupIds)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($this->cleanupIds), '?'));
        $db           = $this->prefix();
        $this->pdo()->prepare(
            "DELETE FROM {$db}registrations WHERE id IN ({$placeholders})"
        )->execute($this->cleanupIds);
        $this->cleanupIds = [];
    }

    // ==================================================================
    // registration_finalize_payment() — 6 tests
    // ==================================================================

    /**
     * Happy path: a draft registration is finalized for the first time.
     * Must return a non-null array, flip status to 'paid', store the
     * Stripe session ID, and set confirmation_email_sent = 1.
     */
    #[Test]
    public function firstCallOnDraftReturnsDataAndClaimsEmail(): void
    {
        $id     = $this->insertRegistration();
        $result = registration_finalize_payment($this->pdo(), $id, 'cs_test_first');

        $this->assertIsArray($result, 'Should return array when email is not yet claimed');
        $this->assertSame($id, (int) $result['id']);
        $this->assertArrayHasKey('kids', $result, 'Returned data must include a kids key');
        $this->assertIsArray($result['kids']);

        $row = $this->fetchRow($id);
        $this->assertSame('paid',          $row['status']);
        $this->assertSame('cs_test_first', $row['stripe_session_id']);
        $this->assertSame(1, (int) $row['confirmation_email_sent']);
    }

    /**
     * Idempotency: a second call on the same registration returns null
     * (the email was already claimed) and leaves the DB unchanged.
     */
    #[Test]
    public function secondCallReturnsNullBecauseEmailAlreadyClaimed(): void
    {
        $id = $this->insertRegistration();
        registration_finalize_payment($this->pdo(), $id, 'cs_test_claim');

        $result = registration_finalize_payment($this->pdo(), $id, 'cs_test_duplicate');

        $this->assertNull($result, 'Second call must return null — email already claimed');

        $row = $this->fetchRow($id);
        $this->assertSame('paid', $row['status'], 'Status must stay paid');
        $this->assertSame(1, (int) $row['confirmation_email_sent']);
    }

    /**
     * Webhook-wins-the-race: row is already 'paid' but email flag is 0.
     * The function must still claim the email and return registration data.
     */
    #[Test]
    public function alreadyPaidButEmailNotSentStillClaimsEmail(): void
    {
        $id = $this->insertRegistration([
            'status'                  => 'paid',
            'confirmation_email_sent' => 0,
        ]);

        $result = registration_finalize_payment($this->pdo(), $id, 'cs_test_webhook_won');

        $this->assertIsArray($result, 'Must return data when email has not yet been claimed');

        $row = $this->fetchRow($id);
        $this->assertSame('paid', $row['status']);
        $this->assertSame(1, (int) $row['confirmation_email_sent']);
    }

    /**
     * Both status = 'paid' AND confirmation_email_sent = 1: pure no-op,
     * must return null without changing any data.
     */
    #[Test]
    public function alreadyPaidAndEmailSentReturnsNull(): void
    {
        $id = $this->insertRegistration([
            'status'                  => 'paid',
            'confirmation_email_sent' => 1,
        ]);

        $result = registration_finalize_payment($this->pdo(), $id, 'cs_test_already_done');

        $this->assertNull($result);

        $row = $this->fetchRow($id);
        $this->assertSame('paid', $row['status']);
        $this->assertSame(1, (int) $row['confirmation_email_sent']);
    }

    /**
     * Non-existent registration ID must return null without throwing.
     */
    #[Test]
    public function nonExistentRegistrationReturnsNull(): void
    {
        $result = registration_finalize_payment($this->pdo(), 999_999_999, 'cs_test_ghost');
        $this->assertNull($result);
    }

    /**
     * The returned array must always embed a 'kids' key — even when no
     * child rows are attached to the registration.
     */
    #[Test]
    public function returnedArrayAlwaysContainsKidsKey(): void
    {
        $id     = $this->insertRegistration();
        $result = registration_finalize_payment($this->pdo(), $id, 'cs_test_kids_key');

        $this->assertArrayHasKey('kids', $result);
        $this->assertIsArray($result['kids']);
        $this->assertCount(0, $result['kids'], 'No kids were inserted — should be empty array');
    }

    // ==================================================================
    // payment_finalize_and_notify() — 4 tests
    //
    // APP_ENV === 'test' in bootstrap, so send_registration_confirmation_email()
    // is never called — the coordinator's DB side-effects are all that matter here.
    // ==================================================================

    /**
     * First call on a draft registration: must mark it as paid and claim
     * the email flag, even though no email is dispatched in test mode.
     */
    #[Test]
    public function notifyFirstCallMarksPaidAndClaimsEmailFlag(): void
    {
        $id = $this->insertRegistration();

        payment_finalize_and_notify($this->pdo(), $id, 'cs_notify_first');

        $row = $this->fetchRow($id);
        $this->assertSame('paid', $row['status'],
            'payment_finalize_and_notify() must flip status to paid');
        $this->assertSame(1, (int) $row['confirmation_email_sent'],
            'payment_finalize_and_notify() must claim the email flag');
        $this->assertSame('cs_notify_first', $row['stripe_session_id'],
            'Stripe session ID must be persisted');
    }

    /**
     * Second call on the same registration: must be a silent no-op
     * (function returns void — we verify the DB is unchanged).
     */
    #[Test]
    public function notifySecondCallIsIdempotentNoOp(): void
    {
        $id = $this->insertRegistration();
        payment_finalize_and_notify($this->pdo(), $id, 'cs_notify_first');

        // Capture state after the first call.
        $rowAfterFirst = $this->fetchRow($id);

        payment_finalize_and_notify($this->pdo(), $id, 'cs_notify_second');

        // State must not change.
        $rowAfterSecond = $this->fetchRow($id);
        $this->assertSame($rowAfterFirst['status'],                 $rowAfterSecond['status']);
        $this->assertSame($rowAfterFirst['confirmation_email_sent'], $rowAfterSecond['confirmation_email_sent']);
        $this->assertSame($rowAfterFirst['stripe_session_id'],      $rowAfterSecond['stripe_session_id']);
    }

    /**
     * Non-existent registration: the function must not throw — it delegates
     * gracefully to registration_finalize_payment() which returns null.
     */
    #[Test]
    public function notifyNonExistentRegistrationDoesNotThrow(): void
    {
        payment_finalize_and_notify($this->pdo(), 999_999_999, 'cs_notify_ghost');
        $this->assertTrue(true, 'No exception must be thrown for a missing registration');
    }

    /**
     * Row already paid with email flag set: the coordinator must leave
     * DB state untouched and return without error.
     */
    #[Test]
    public function notifyAlreadyCompleteRegistrationIsNoOp(): void
    {
        $id = $this->insertRegistration([
            'status'                  => 'paid',
            'confirmation_email_sent' => 1,
        ]);

        payment_finalize_and_notify($this->pdo(), $id, 'cs_notify_done');

        $row = $this->fetchRow($id);
        $this->assertSame('paid', $row['status']);
        $this->assertSame(1, (int) $row['confirmation_email_sent']);
    }
}
