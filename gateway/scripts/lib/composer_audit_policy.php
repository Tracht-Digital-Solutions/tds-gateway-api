<?php
declare(strict_types=1);

/**
 * The decision half of scripts/composer-audit.php, kept free of I/O so the
 * suite can pin it (tests/ComposerAuditPolicyTest.php).
 *
 * @return array{unreadable: bool, blocking: int, findings: list<array{package: string, title: string, id: string, severity: string, blocking: bool}>}
 */
function tds_composer_audit_classify(string $json): array
{
    $data = json_decode($json, true);
    if (!is_array($data)) {
        return ['unreadable' => true, 'blocking' => 0, 'findings' => []];
    }

    $findings = [];
    $blocking = 0;
    // `advisories` is an object keyed by package — or an empty LIST when there
    // are none, which is what `composer audit` prints for a clean tree.
    foreach ((array) ($data['advisories'] ?? []) as $package => $list) {
        foreach ((array) $list as $advisory) {
            if (!is_array($advisory)) {
                continue;
            }
            $severity = strtolower(trim((string) ($advisory['severity'] ?? '')));
            $severity = $severity === '' ? 'unknown' : $severity;
            $isBlocking = in_array($severity, ['high', 'critical'], true);
            $blocking += $isBlocking ? 1 : 0;
            $findings[] = [
                'package' => (string) $package,
                'title' => (string) ($advisory['title'] ?? ''),
                'id' => (string) ($advisory['cve'] ?? $advisory['advisoryId'] ?? ''),
                'severity' => $severity,
                'blocking' => $isBlocking,
            ];
        }
    }

    return ['unreadable' => false, 'blocking' => $blocking, 'findings' => $findings];
}
