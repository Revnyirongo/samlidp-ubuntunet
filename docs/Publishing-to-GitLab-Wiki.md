# Publishing to GitLab Wiki

## Recommendation

Use a split model:

- keep the authoritative documentation in this repository under `docs/`
- publish the same Markdown content to `gitlab.ubuntunet.net` for operator-facing browsing

This gives you:

- versioned documentation in Git
- reviewable documentation changes alongside code changes
- a clean public or internal reading surface in GitLab

## Should It Be a Wiki?

Yes, a GitLab wiki is a reasonable publishing target if:

- the audience is mostly operators, implementers, and administrators
- the documentation will evolve continuously
- you want simple page editing and navigation

However, the wiki should not become the only source of truth. The repo should remain the canonical source so that releases and documentation stay aligned.

## Recommended Structure for GitLab

Publish these pages:

- `Home`
- `Architecture`
- `Installation`
- `Configuration`
- `Tenant Operations`
- `Service Providers and Federations`
- `User Lifecycle and Email`
- `Operations`
- `Security and 2FA`
- `Troubleshooting`

The files in `docs/` are already organized for that structure.

## Suggested Workflow

1. write and review documentation in this repository
2. merge documentation changes with code changes
3. copy or sync selected files into the GitLab wiki
4. treat the wiki as the published reading surface

## Practical Options

### Option 1: Manual copy

Low automation, simple to manage.

### Option 2: Wiki repository mirror

Clone the GitLab wiki repository and sync `docs/*.md` into it with a script or CI job.

### Option 3: GitLab Pages

If you later want stronger navigation, search, and versioned releases, consider moving from wiki pages to GitLab Pages or a static site generator.

## Notes for This Repository

- `docs/Home.md` can serve as the wiki landing page content
- `docs/_Sidebar.md` can be reused as navigation content
- keep internal runbooks or secrets out of the published wiki
