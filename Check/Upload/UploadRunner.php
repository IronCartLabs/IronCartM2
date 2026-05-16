<?php

/**
 * IronCart_Scan — upload orchestrator.
 *
 * Sits between `bin/magento ironcart:scan --upload` and the hardened HTTP
 * client. Owns the policy decisions the CLI command MUST get right every
 * time: opt-in gate, token check, payload-size guard, no-admin-email
 * invariant, response-handling rules.
 *
 * The CLI command is a thin shell that delegates to this class so the
 * unit tests can exercise the policy directly without spinning up a
 * Symfony Console application.
 *
 * @copyright Copyright (c) Ironcart (https://ironcart.dev)
 * @license   MIT
 */

declare(strict_types=1);

namespace IronCart\Scan\Check\Upload;

use Throwable;

/**
 * Orchestrates the `--upload` flow.
 */
class UploadRunner
{
    /**
     * Keys the payload must never contain at any nesting level. The
     * recursive walk in {@see assertNoPiiKeys()} enforces this; the
     * unit test mirrors the check by introspecting the serialised JSON.
     *
     * @var list<string>
     */
    private const FORBIDDEN_PAYLOAD_KEYS = [
        'admin_email',
        'operator_email',
        'admin_username',
        'admin_user_email',
    ];

    public function __construct(
        private readonly UploadConfig $config,
        private readonly UploadPayloadBuilder $payloadBuilder,
        private readonly UploadClient $client,
        private readonly string $moduleVersion
    ) {
    }

    /**
     * Execute the upload flow.
     *
     * @param list<array{
     *     id:string,
     *     title:string,
     *     severity:string,
     *     evidence:mixed,
     *     remediation_url:string
     * }> $findings
     */
    public function run(array $findings): UploadRunnerOutcome
    {
        // Gate 1 — opt-in default OFF. Never opens a socket.
        if (!$this->config->isEnabled()) {
            return new UploadRunnerOutcome(
                UploadRunnerOutcome::EXIT_OK,
                'Upload disabled (admin → Stores → Configuration → Ironcart → Scan Upload → Enable). Skipping.',
                ''
            );
        }

        // Gate 2 — token must be set. Misconfiguration → non-zero so
        // cron picks it up; never opens a socket.
        $token = $this->config->token();
        if ($token === '') {
            return new UploadRunnerOutcome(
                UploadRunnerOutcome::EXIT_MISCONFIGURED,
                '',
                'Upload enabled but no token configured. Paste your ironcart.dev token at '
                . 'Stores → Configuration → Ironcart → Scan Upload → Token.'
            );
        }

        // Build payload. Module-side size guards short-circuit before
        // any socket work.
        try {
            $payload = $this->payloadBuilder->build($findings);
        } catch (PayloadTooLargeException $e) {
            return new UploadRunnerOutcome(
                UploadRunnerOutcome::EXIT_MISCONFIGURED,
                '',
                $e->getMessage() . ' Payload would exceed server limit.'
            );
        } catch (Throwable $e) {
            return new UploadRunnerOutcome(
                UploadRunnerOutcome::EXIT_TRANSPORT,
                '',
                'Unable to build upload payload: ' . $e->getMessage()
            );
        }

        // Hard invariant — no admin email / operator email anywhere in
        // the payload tree. This is a defense-in-depth check in case a
        // future refactor accidentally re-adds the field; the IronCartWeb
        // ingest endpoint will also 422 on these keys.
        if (!$this->assertNoPiiKeys($payload)) {
            return new UploadRunnerOutcome(
                UploadRunnerOutcome::EXIT_MISCONFIGURED,
                '',
                'Refusing to upload: payload contains a forbidden PII key. '
                . 'This is a bug — please file at https://github.com/IronCartLabs/IronCartM2/issues.'
            );
        }

        // Send.
        $result = $this->client->post(
            $this->config->endpoint(),
            $payload,
            $token,
            'ironcart-magento-scan/' . $this->moduleVersion,
            $this->config->allowedHost()
        );

        if ($result->ok()) {
            $stdout = $result->viewUrl !== null
                ? sprintf('Scan uploaded: %s', $result->viewUrl)
                : 'Scan uploaded (server returned no view_url).';
            return new UploadRunnerOutcome(
                UploadRunnerOutcome::EXIT_OK,
                $stdout,
                '',
                $result->viewUrl
            );
        }

        return $this->categoryToOutcome($result);
    }

    /**
     * Walk the payload recursively and reject any nested key from the
     * `FORBIDDEN_PAYLOAD_KEYS` list. Returns true if the payload is
     * clean.
     *
     * @param mixed $node
     */
    private function assertNoPiiKeys(mixed $node): bool
    {
        if (!is_array($node)) {
            return true;
        }
        foreach ($node as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), self::FORBIDDEN_PAYLOAD_KEYS, true)) {
                return false;
            }
            if (!$this->assertNoPiiKeys($value)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Map a non-OK client result to a runner outcome with operator-
     * friendly stderr. The response body is NEVER quoted — only the
     * stable category label.
     */
    private function categoryToOutcome(UploadClientResult $result): UploadRunnerOutcome
    {
        return match ($result->category) {
            UploadClientResult::CATEGORY_HOST_REJECTED => new UploadRunnerOutcome(
                UploadRunnerOutcome::EXIT_MISCONFIGURED,
                '',
                'Upload endpoint host does not match the configured allow-list. '
                . 'Check Stores → Configuration → Ironcart → Scan Upload → Endpoint.'
            ),
            UploadClientResult::CATEGORY_TIMEOUT => new UploadRunnerOutcome(
                UploadRunnerOutcome::EXIT_TRANSPORT,
                '',
                'Upload timed out after retry. Network or server-side congestion; try again later.'
            ),
            UploadClientResult::CATEGORY_TRANSPORT => new UploadRunnerOutcome(
                UploadRunnerOutcome::EXIT_TRANSPORT,
                '',
                'Upload transport failure (DNS, TLS, or libcurl error). Check outbound HTTPS connectivity.'
            ),
            UploadClientResult::CATEGORY_AUTH => new UploadRunnerOutcome(
                UploadRunnerOutcome::EXIT_MISCONFIGURED,
                '',
                'Upload rejected: invalid or expired token. Paste a fresh token from ironcart.dev.'
            ),
            UploadClientResult::CATEGORY_PAYLOAD_TOO_LARGE => new UploadRunnerOutcome(
                UploadRunnerOutcome::EXIT_MISCONFIGURED,
                '',
                'Upload rejected: server reported payload too large. '
                . 'Trim the scan output or open an issue at https://github.com/IronCartLabs/IronCartM2/issues.'
            ),
            UploadClientResult::CATEGORY_BAD_REQUEST => new UploadRunnerOutcome(
                UploadRunnerOutcome::EXIT_SERVER,
                '',
                'Upload rejected: server reports the payload shape is invalid (bad_request). '
                . 'Update the IronCart_Scan module to the latest version.'
            ),
            UploadClientResult::CATEGORY_SERVER => new UploadRunnerOutcome(
                UploadRunnerOutcome::EXIT_SERVER,
                '',
                'Upload failed: server error after retry. Try again later or check status.ironcart.dev.'
            ),
            default => new UploadRunnerOutcome(
                UploadRunnerOutcome::EXIT_SERVER,
                '',
                'Upload failed: unexpected server response.'
            ),
        };
    }
}
