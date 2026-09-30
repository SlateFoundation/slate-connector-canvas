<?php

namespace Slate\Connectors\Canvas;

/**
 * Plans the login updates needed to converge a Canvas user's logins on a
 * Slate person's primary email and username.
 *
 * This class makes no API calls: it takes the Canvas user and its logins and
 * returns an ordered list of login updates plus any warnings, so that callers
 * can log the plan in pretend mode and execute it otherwise.
 *
 * Login convergence contract:
 *
 * 1. The sign-in login is the active login whose unique_id equals the primary
 *    email (compared trimmed and case-insensitively), since Slate signs people
 *    in to Canvas with the primary email as SAML NameID
 * 2. The username must be the sis_user_id of exactly one login on the user,
 *    preferably the sign-in login
 * 3. Other logins are left in place and never deleted. When the sign-in login
 *    needs the username stamped, SIS IDs held by other logins are cleared first
 * 4. When any login on the user already has the target unique_id, no rename is
 *    planned: that login is the sign-in login
 * 5. Otherwise one login is renamed: the one already carrying the username as
 *    sis_user_id, else the first active login
 * 6. Writes are ordered so that an ID is freed before it is claimed: SIS ID
 *    clears, then the rename, then the SIS ID stamp
 *
 * Canvas rejecting a rename or SIS ID as already in use (i.e. held by a
 * different Canvas user) is handled by the caller; see isAlreadyInUseError()
 */
class LoginPlanner
{
    const ACTION_CLEAR_SIS_ID = 'clear-sis-id';
    const ACTION_RENAME = 'rename';
    const ACTION_SET_SIS_ID = 'set-sis-id';

    /**
     * Plan login updates for a Canvas user.
     *
     * @param array  $canvasUser Canvas user or profile response
     * @param array  $logins     Canvas logins response for the user
     * @param string $email      desired unique_id for the sign-in login
     * @param string $username   desired sis_user_id
     *
     * @return array with keys:
     *               - operations: ordered list of ['action', 'loginId', 'changes', 'previous'],
     *               where changes/previous are keyed by Canvas login API field
     *               - warnings: list of ['message', 'context'] suitable for a PSR-3 logger
     */
    public static function plan(array $canvasUser, array $logins, $email, $username)
    {
        $email = trim((string) $email);
        $username = trim((string) $username);
        $normalizedEmail = static::normalizeUniqueId($email);

        $warnings = [];
        $clearOperations = [];
        $renameOperations = [];
        $stampOperations = [];

        $canvasUserId = isset($canvasUser['id']) ? $canvasUser['id'] : null;

        // index logins of interest
        $signInLogin = null;
        $inactiveEmailLogin = null;
        $usernameLogin = null;
        $firstActiveLogin = null;

        foreach ($logins as $login) {
            $active = static::isActive($login);

            if ($active && !$firstActiveLogin) {
                $firstActiveLogin = $login;
            }

            if ('' !== $normalizedEmail && static::normalizeUniqueId(static::getField($login, 'unique_id')) === $normalizedEmail) {
                if ($active && !$signInLogin) {
                    $signInLogin = $login;
                } elseif (!$active && !$inactiveEmailLogin) {
                    $inactiveEmailLogin = $login;
                }
            }

            if ('' !== $username && !$usernameLogin && static::getField($login, 'sis_user_id') === $username) {
                $usernameLogin = $login;
            }
        }

        // determine which login should carry the email, renaming one if none does yet
        $targetLogin = $signInLogin;

        if (!$targetLogin && $inactiveEmailLogin) {
            // a rename would be rejected since this login already holds the email
            $targetLogin = $inactiveEmailLogin;
            $warnings[] = [
                'message' => 'Canvas login {canvasLoginId} for {email} on Canvas user {canvasUserId} is not active ({canvasLoginState}), sign-in will fail until it is reactivated in Canvas',
                'context' => [
                    'canvasLoginId' => $inactiveEmailLogin['id'],
                    'canvasLoginState' => static::getField($inactiveEmailLogin, 'workflow_state'),
                    'canvasUserId' => $canvasUserId,
                    'email' => $email,
                ],
            ];
        }

        if (!$targetLogin) {
            if ('' === $email) {
                $warnings[] = [
                    'message' => 'No email to set as Canvas login for Canvas user {canvasUserId}, leaving login IDs unchanged',
                    'context' => [
                        'canvasUserId' => $canvasUserId,
                    ],
                ];
                $targetLogin = $usernameLogin ?: $firstActiveLogin;
            } elseif ($targetLogin = $usernameLogin ?: $firstActiveLogin) {
                $renameOperations[] = static::buildOperation(static::ACTION_RENAME, $targetLogin, 'unique_id', $email);
            } else {
                $warnings[] = [
                    'message' => 'Canvas user {canvasUserId} has no active login to set {email} on',
                    'context' => [
                        'canvasUserId' => $canvasUserId,
                        'email' => $email,
                    ],
                ];
            }
        }

        // stamp username on the target login, freeing SIS IDs held by any other login first
        if ('' !== $username && $targetLogin && static::getField($targetLogin, 'sis_user_id') !== $username) {
            foreach ($logins as $login) {
                if ($login['id'] == $targetLogin['id'] || '' === static::getField($login, 'sis_user_id')) {
                    continue;
                }

                $clearOperations[] = static::buildOperation(static::ACTION_CLEAR_SIS_ID, $login, 'sis_user_id', '');
            }

            $stampOperations[] = static::buildOperation(static::ACTION_SET_SIS_ID, $targetLogin, 'sis_user_id', $username);
        }

        return [
            'operations' => array_merge($clearOperations, $renameOperations, $stampOperations),
            'warnings' => $warnings,
        ];
    }

    /**
     * Normalize a login unique_id for comparison, as Canvas matches them case-insensitively.
     */
    public static function normalizeUniqueId($uniqueId)
    {
        return strtolower(trim((string) $uniqueId));
    }

    /**
     * Whether an API exception is Canvas refusing a unique_id or sis_user_id
     * because it is already in use.
     *
     * Matches API errors like:
     *
     * - Canvas reports: ID already in use for this account and authentication provider (unique_id)
     * - Canvas reports: SIS ID "jdoe" is already in use (sis_user_id)
     */
    public static function isAlreadyInUseError(\Throwable $e)
    {
        return $e instanceof \RuntimeException
            && preg_match('/\balready in use\b/i', $e->getMessage());
    }

    protected static function isActive(array $login)
    {
        return empty($login['workflow_state']) || 'active' == $login['workflow_state'];
    }

    protected static function getField(array $login, $field)
    {
        return isset($login[$field]) ? trim((string) $login[$field]) : '';
    }

    protected static function buildOperation($action, array $login, $field, $value)
    {
        return [
            'action' => $action,
            'loginId' => $login['id'],
            'changes' => [
                "login[{$field}]" => $value,
            ],
            'previous' => [
                "login[{$field}]" => isset($login[$field]) ? $login[$field] : null,
            ],
        ];
    }
}
