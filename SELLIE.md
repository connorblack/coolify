# Sellie build of Coolify

Branch `sellie` = the upstream release tag (currently `v4.3.17`) plus the commits below. It exists so
NixOS hosts can be Coolify servers before coollabsio/coolify#7170 (NixOS support) is merged and
released. When that happens, delete the three `nixos:` commits and this image is no longer needed.

## Commits on top of the tag

| Commit | Files | From #7170? |
|---|---|---|
| `nixos: accept ID=nixos as a supported OS` | `bootstrap/helpers/constants.php` | yes, unchanged |
| `nixos: guidance-only Docker step` | `app/Actions/Server/InstallDocker.php`, `tests/Unit/NixosServerSetupTest.php` | yes, adapted: returns before the daemon.json merge and `systemctl restart docker`, which are invalid on NixOS; guidance text points at `virtualisation.docker` |
| `nixos: validation messages when Docker is missing` | `app/Livewire/Server/ValidateAndInstall.php` | hunks 2 and 3 only |
| `ci: sellie image` | `.github/workflows/sellie-image.yml` | no |
| `docs: SELLIE.md` | this file | no |

Dropped from #7170 on purpose: `CheckUpdates.php`, `UpdatePackage.php`, `patches.blade.php`,
`NixosPatchCheckTest.php` (they run `nix-channel --update nixos` and `nixos-rebuild switch`; the
Sellie hosts are flake-based, so those commands would fail or rebuild from the wrong source), and
hunk 1 of `ValidateAndInstall.php` (it stored an error string in `validation_logs` on a healthy host).
With those absent, the Patches page reports "Unsupported package manager" for NixOS servers and
runs nothing, which is the intended no-op. Never use "Validate & Install" on a NixOS server; use
plain validation.

## Image

`ghcr.io/connorblack/coolify:<version>` (plus `<version>-sellie.<sha7>`), built by
`.github/workflows/sellie-image.yml` from `docker/production/Dockerfile` with
`COOLIFY_VERSION=<version>` on tags `sellie/v<version>`. The tag equals the upstream version so the
instance's updater (`upgrade.sh <version> …`) finds it. The instance selects it through the
documented persistent override `/data/coolify/source/docker-compose.custom.yml`:

```yaml
services:
  coolify:
    image: "ghcr.io/connorblack/coolify:${LATEST_IMAGE:-4.3.17}"
```

`REGISTRY_URL` stays `docker.io`; helper, realtime and sentinel images stay vendor images.
Deleting the file returns the instance to the vendor image on the next update.

## Following an upstream release

```
git fetch upstream --tags
git rebase --onto vX.Y.Z vPREV sellie
git diff vX.Y.Z..sellie --stat            # only the files in the table above
grep -rn "nix-channel\|nixos-rebuild switch" app/   # must be empty
docker run --rm -v "$PWD:/app" -w /app php:8.4-cli-alpine php bootstrap/getVersion.php   # X.Y.Z
git tag -a sellie/vX.Y.Z -m "Sellie build of Coolify X.Y.Z" && git push origin sellie sellie/vX.Y.Z
```

Review upstream's changes to `scripts/upgrade.sh`, `docker-compose*.yml`, `.env.production`,
`app/Actions/Server/{InstallDocker,ValidateServer}.php` and `app/Models/Server.php::validateOS`
between the tags; any of them can change the assumptions above. After CI publishes the image,
run the update from the Coolify dashboard (Settings → Updates). Automatic updates stay disabled so
an update never runs before its image exists; a missing tag makes `upgrade.sh` abort before any
container is stopped.
