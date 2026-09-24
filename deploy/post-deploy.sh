#!/bin/bash
# Run by the VPS deploy script (hypermed-deploy) after `migrate`, before the
# new release goes live — the VPS equivalent of the seeder chain in
# railway.toml's startCommand. Every seeder here guards itself and no-ops
# after its first successful run; keep this list in sync with railway.toml
# until Railway is retired.
#
#   $1  = the new release directory
#   PHP = the PHP binary (set by hypermed-deploy)
set -euo pipefail
cd "$1"
PHP="${PHP:-php}"
for seeder in \
  PermissionSeeder \
  TestAccessControlSeeder \
  RealFacilityImportSeeder \
  UpdateFacilityCoordinatesSeeder \
  ImportGovernmentFacilityCoordinatesSeeder \
  ImportNationalFacilityRegistrySeeder \
  RemoveDemoDataSeeder \
  ImportDvasOpgCbctSeeder \
  NormalizeKilimanjaroDistrictsSeeder \
  FlowDepartmentSeeder \
  DocumentSequenceSeeder \
  NotificationTemplateSeeder
do
  "$PHP" artisan db:seed --class="$seeder" --force
done
