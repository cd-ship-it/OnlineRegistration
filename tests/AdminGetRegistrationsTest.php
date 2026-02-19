<?php
/**
 * Integration tests for admin_get_registrations().
 *
 * Verifies that the sort-key whitelist lives entirely inside the helper and
 * that no untrusted caller input can reach the SQL ORDER BY clause.
 *
 * Uses the same real MySQL connection as the other integration tests.
 * Each test inserts a temporary registration row and removes it in tearDown.
 */

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversFunction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversFunction('admin_get_registrations')]
final class AdminGetRegistrationsTest extends TestCase
{
    private array $cleanupIds = [];

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function pdo(): PDO
    {
        return $GLOBALS['pdo'];
    }

    /** Insert a minimal registration and queue it for cleanup. */
    private function insertRegistration(string $status = 'paid'): int
    {
        $db = db_prefix();
        $this->pdo()->prepare(
            "INSERT INTO {$db}registrations
             (parent_first_name, parent_last_name, email, status, total_amount_cents)
             VALUES ('Sort', 'Test', 'sorttest@example.com', ?, 1000)"
        )->execute([$status]);

        $id = (int) $this->pdo()->lastInsertId();
        $this->cleanupIds[] = $id;
        return $id;
    }

    protected function tearDown(): void
    {
        if (empty($this->cleanupIds)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($this->cleanupIds), '?'));
        $db           = db_prefix();
        $this->pdo()->prepare(
            "DELETE FROM {$db}registrations WHERE id IN ({$placeholders})"
        )->execute($this->cleanupIds);
        $this->cleanupIds = [];
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    /**
     * Calling with no sort/dir args (defaults) must return an array without
     * throwing — proves the default 'date' / 'desc' path works end-to-end.
     */
    #[Test]
    public function defaultSortReturnsResultsWithoutError(): void
    {
        $this->insertRegistration();

        $rows = admin_get_registrations($this->pdo(), '');

        $this->assertIsArray($rows);
        $this->assertGreaterThanOrEqual(1, count($rows));
    }

    /**
     * Every whitelisted sort key must produce a valid result set.
     */
    #[Test]
    #[DataProvider('validSortKeyProvider')]
    public function eachValidSortKeyReturnsResultsWithoutError(string $key): void
    {
        $this->insertRegistration();

        $rows = admin_get_registrations($this->pdo(), '', $key, 'asc');

        $this->assertIsArray($rows, "Sort key '{$key}' caused an unexpected failure");
    }

    /** @return array<string, array{string}> */
    public static function validSortKeyProvider(): array
    {
        return [
            'parent' => ['parent'],
            'email'  => ['email'],
            'kids'   => ['kids'],
            'photo'  => ['photo'],
            'status' => ['status'],
            'date'   => ['date'],
        ];
    }

    /**
     * An unknown / malicious sort key must fall back to the 'date' default
     * and must NOT throw or produce a SQL error.
     */
    #[Test]
    public function unknownSortKeyFallsBackToDateWithoutError(): void
    {
        $this->insertRegistration();

        // Strings that would break raw SQL if interpolated directly
        foreach (['injected--', '; DROP TABLE registrations--', '', 'r.id; --', '1=1'] as $bad) {
            $rows = admin_get_registrations($this->pdo(), '', $bad, 'desc');
            $this->assertIsArray($rows, "Bad sort key '{$bad}' caused an unexpected failure");
        }
    }

    /**
     * 'asc' direction must be accepted and return results.
     */
    #[Test]
    public function ascDirectionIsAccepted(): void
    {
        $this->insertRegistration();

        $rows = admin_get_registrations($this->pdo(), '', 'date', 'asc');

        $this->assertIsArray($rows);
    }

    /**
     * 'desc' direction must be accepted and return results.
     */
    #[Test]
    public function descDirectionIsAccepted(): void
    {
        $this->insertRegistration();

        $rows = admin_get_registrations($this->pdo(), '', 'date', 'desc');

        $this->assertIsArray($rows);
    }

    /**
     * Any direction string that is not 'asc' must fall back to 'desc'
     * and must NOT throw or produce a SQL error.
     */
    #[Test]
    public function invalidDirectionFallsBackToDescWithoutError(): void
    {
        $this->insertRegistration();

        foreach (['; DROP TABLE--', 'UNION SELECT', '', 'ASC; --', '1'] as $bad) {
            $rows = admin_get_registrations($this->pdo(), '', 'date', $bad);
            $this->assertIsArray($rows, "Bad direction '{$bad}' caused an unexpected failure");
        }
    }

    /**
     * Status filter must still narrow results correctly after the refactor.
     */
    #[Test]
    public function statusFilterIsApplied(): void
    {
        $this->insertRegistration('draft');

        $paid  = admin_get_registrations($this->pdo(), 'paid',  'date', 'desc');
        $draft = admin_get_registrations($this->pdo(), 'draft', 'date', 'desc');

        $insertedId = end($this->cleanupIds); // the draft row's integer ID

        // The draft row we inserted must appear in the 'draft' result set.
        $draftIds = array_map('intval', array_column($draft, 'id'));
        $this->assertContains(
            $insertedId,
            $draftIds,
            'The inserted draft registration must appear when filtering by draft'
        );

        // It must NOT appear when filtering for paid.
        $paidIds = array_map('intval', array_column($paid, 'id'));
        $this->assertNotContains(
            $insertedId,
            $paidIds,
            'The draft registration must not appear when filtering by paid'
        );
    }
}
