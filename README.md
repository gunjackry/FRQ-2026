# FRQ-p1-v1

WordPress plugin scaffold for FRQ Phase 1 (Free Route Quiz).

## Features in this starter

- Shortcode: `[frq_p1_v1]`
- Guest-friendly submission flow
- Starts with three generations pre-seeded using an interactive node tree
- Two-pane UX: clickable family nodes on the left + selected member editor on the right
- Per-person controls to open a generation above or below (including minors below)
- Prompting for fourth-generation expansion when generation 3 is active
- Collects core known fields per selected node (Name, DOB, POB) with unknown-by-default behavior
- Optional “unlock” detail buttons for occupation at child birth, marital status, and crown service
- Tree-link controls to add above (step/adopted/legal guardian) and below (son/daughter/step/adopted child)
- Branch research flag for each person
- Contact name + email captured for follow-up (required only on final submit)
- Consent checkbox before final submit
- Honeypot + nonce + simple IP rate limiting for submission hardening
- Admin queue page for latest submissions
- Automatic `wp_mail` notification to team on submitted assessments with per-person relationship summaries
- Notification email settings page for recipients and sender identity
- Versioned DB migration routine for safe schema updates
- Share read-only copies with family/friends via email and tokenized links
- Public read-only share view with CTA to create a new tree
- Save as draft or submit phase 1
- Persists submissions in custom DB table

## Installation

1. Copy plugin folder into `wp-content/plugins/frq-p1-v1`.
2. Activate **FRQ-p1-v1** in WordPress admin.
3. Add shortcode `[frq_p1_v1]` to a page.
4. Review submissions under **WP Admin > FRQ Phase 1**.
5. Configure notification settings under **WP Admin > FRQ Phase 1 > Settings**.

## Notes

This is an MVP scaffold for Phase 1 and can be extended with:

- proper family relationship graph edges
- richer share management UI (revoke/list in admin)
- email follow-up workflow for supporting documents later in the journey
- optional custom recipient routing via `frq_p1_v1_notification_recipients` filter
- CRM/email automation integrations
