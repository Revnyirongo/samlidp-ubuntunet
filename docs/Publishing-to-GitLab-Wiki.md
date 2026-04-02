# Publishing to GitLab Wiki

## Recommended Model

Use this split model:

- keep the authoritative documentation in the repository under `docs/`
- publish the same Markdown pages to the GitLab wiki for browsing

This keeps documentation versioned with the code while still giving operators a simple reading surface.

## Why Use The Wiki

GitLab wiki works well when:

- the audience is operators, implementers, and administrators
- the content changes over time
- you want page-based navigation without introducing a separate documentation stack

## Recommended Published Pages

- `Home`
- `Architecture`
- `Installation`
- `Configuration`
- `Tenant Operations`
- `Service Providers and Federations`
- `User Lifecycle and Email`
- `Operations`
- `Deployment Checklist`
- `Security and 2FA`
- `Troubleshooting`

## Workflow

1. edit documentation in the repository
2. review docs alongside code changes
3. sync selected Markdown files to the GitLab wiki
4. treat the wiki as the published reading surface

## Notes

- `docs/Home.md` should be the wiki landing page
- `docs/_Sidebar.md` can be reused for navigation
- do not publish internal secrets, hostnames, or credentials in the wiki
