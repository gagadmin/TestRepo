/**
 * Resolve mandatory identity steps in their required order.
 *
 * A newly provisioned account can require both a password change and MFA
 * enrolment. Password change must finish first. Treating the checks as two
 * independent redirects makes the password page redirect to MFA while the MFA
 * page redirects back to password change, producing an infinite router loop.
 */
export function identityGateRedirect(auth, to) {
    if (auth.mustChangePassword) {
        return to.name === 'change-password' ? null : { name: 'change-password' };
    }

    if (auth.mustEnrolTwoFactor) {
        return to.name === 'two-factor-setup' ? null : { name: 'two-factor-setup' };
    }

    return null;
}

export default identityGateRedirect;
