# PostgreSQL with CloudNativePG

Databases are `Cluster` resources reconciled by the CloudNativePG operator
(`kubernetes/apps/cloudnative-pg.yaml`). Why: [ADR 0004](adr/0004-run-postgresql-with-cloudnative-pg.md).

## Giving an app a database

1. Add a `Cluster` to `kubernetes/manifests/workloads/<app>/`, using
   `nextcloud/database.yaml` as the template. Set `bootstrap.initdb.database`
   and `owner` to what the app expects.
2. Turn off the chart's bundled database and point it at the generated secret
   `<cluster>-app`. Its keys are `username`, `password`, `host`, `port`,
   `dbname` and `uri`; `host` is the read-write Service, `<cluster>-rw`.

Nothing needs sealing: the operator generates the password in-cluster.

## Useful commands

```bash
kubectl -n <ns> get cluster                        # status of every database
kubectl -n <ns> exec -it <cluster>-1 -c postgres -- psql <dbname>
```

## Migrating Nextcloud off the bundled PostgreSQL

Merging the switch prunes the old `nextcloud-postgresql` StatefulSet, so the
dump has to be taken **before** merging. Its volume
(`data-nextcloud-postgresql-0`) is left behind by the prune, which is the
fallback if anything below goes wrong.

1. Put Nextcloud in maintenance mode and dump the old database:

   ```bash
   kubectl -n nextcloud exec deploy/nextcloud -c nextcloud -- \
     su -s /bin/sh www-data -c "php occ maintenance:mode --on"

   PGPASSWORD=$(kubectl -n nextcloud get secret nextcloud-secrets \
     -o jsonpath='{.data.db-password}' | base64 -d)
   kubectl -n nextcloud exec nextcloud-postgresql-0 -- \
     env PGPASSWORD="$PGPASSWORD" pg_dump -h 127.0.0.1 -U nextcloud -Fc nextcloud \
     > nextcloud.dump
   ```

2. Merge. Argo CD installs the operator, creates `nextcloud-db` and restarts
   Nextcloud against it, still in maintenance mode. Wait for the database:

   ```bash
   kubectl -n nextcloud wait cluster/nextcloud-db --for=condition=Ready --timeout=10m
   ```

3. Restore into it, with every object owned by the `nextcloud` role:

   ```bash
   kubectl -n nextcloud exec -i nextcloud-db-1 -c postgres -- \
     pg_restore --no-owner --no-privileges --role=nextcloud -d nextcloud \
     < nextcloud.dump
   ```

4. Leave maintenance mode and check Nextcloud is healthy:

   ```bash
   kubectl -n nextcloud exec deploy/nextcloud -c nextcloud -- \
     su -s /bin/sh www-data -c "php occ maintenance:mode --off && php occ status"
   ```

5. Once satisfied, delete the old volume and the local dump:

   ```bash
   kubectl -n nextcloud delete pvc data-nextcloud-postgresql-0
   rm nextcloud.dump
   ```

`config.php` still names the old host and password; that is expected. The
`NC_db*` environment variables in `kubernetes/apps/nextcloud.yaml` override it.
