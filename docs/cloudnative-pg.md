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

## Migrating pomopensource off the bundled MySQL

Unlike Nextcloud, this changes database engine, so it is a row copy rather than
a dump. The CloudNativePG Cluster `pomopensource-db` already exists (it ships
before the switch), so the copy runs while the app is in maintenance mode, and
the switch is merged afterwards. Nothing is written in between.

The copy is [scripts/laravel-mysql-to-postgres.php](scripts/laravel-mysql-to-postgres.php),
run inside the app's own image, which carries both database drivers from chart
1.2.0 on. It migrates the PostgreSQL schema with Laravel, then replaces its
contents with MySQL's in one transaction, and fails if any table's row count
differs.

1. Make sure image `prod` and chart `1.2.0` of pomopensource are published, and
   that `pomopensource-db` is ready:

   ```bash
   kubectl -n pomopensource wait cluster/pomopensource-db --for=condition=Ready --timeout=10m
   ```

2. Stop writes, then run the copy:

   ```bash
   kubectl -n pomopensource exec deploy/pomopensource -- php artisan down

   kubectl -n pomopensource create configmap mysql-to-postgres \
     --from-file=docs/scripts/laravel-mysql-to-postgres.php
   kubectl -n pomopensource apply -f - <<'YAML'
   apiVersion: v1
   kind: Pod
   metadata:
     name: mysql-to-postgres
   spec:
     restartPolicy: Never
     containers:
       - name: copy
         image: ghcr.io/corentin-dupaigne/pomopensource/pomopensource:prod
         imagePullPolicy: Always
         # Bypass the image's entrypoint, which would migrate and seed on its own.
         command:
           - sh
           - -c
           - >-
             php artisan migrate --force &&
             php artisan tinker --execute='require "/copy/laravel-mysql-to-postgres.php";'
         env:
           - {name: APP_ENV, value: production}
           - {name: APP_KEY, valueFrom: {secretKeyRef: {name: pomopensource-secrets, key: APP_KEY}}}
           - {name: DB_CONNECTION, value: pgsql}
           - {name: DB_HOST, valueFrom: {secretKeyRef: {name: pomopensource-db-app, key: host}}}
           - {name: DB_PORT, valueFrom: {secretKeyRef: {name: pomopensource-db-app, key: port}}}
           - {name: DB_DATABASE, valueFrom: {secretKeyRef: {name: pomopensource-db-app, key: dbname}}}
           - {name: DB_USERNAME, valueFrom: {secretKeyRef: {name: pomopensource-db-app, key: username}}}
           - {name: DB_PASSWORD, valueFrom: {secretKeyRef: {name: pomopensource-db-app, key: password}}}
           - {name: SOURCE_DB_HOST, value: pomopensource-mysql}
           - {name: SOURCE_DB_DATABASE, value: pomopensource}
           - {name: SOURCE_DB_USERNAME, value: pomopensource}
           - {name: SOURCE_DB_PASSWORD, valueFrom: {secretKeyRef: {name: pomopensource-secrets, key: mysql-password}}}
         volumeMounts:
           - {name: script, mountPath: /copy}
     volumes:
       - name: script
         configMap: {name: mysql-to-postgres}
   YAML
   kubectl -n pomopensource logs -f mysql-to-postgres
   ```

   It ends with one line per table and `Done.`. If it fails, nothing was
   committed: fix the cause, delete the pod and run it again, or bring the app
   back up on MySQL with `php artisan up`.

3. Merge the switch. Argo CD restarts the app on PostgreSQL (the new pod is
   not in maintenance mode) and prunes the MySQL StatefulSet, leaving its volume
   `data-pomopensource-mysql-0` behind as the fallback.

4. Check the site, then clean up:

   ```bash
   kubectl -n pomopensource delete pod/mysql-to-postgres configmap/mysql-to-postgres
   kubectl -n pomopensource delete pvc data-pomopensource-mysql-0   # once satisfied
   ```
