<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            $this->createSqliteTriggers();

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->createMysqlTriggers();

            return;
        }

        if ($driver === 'pgsql') {
            $this->createPostgresTriggers();
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS campaign_recipient_frozen_experiment_insert_guard ON campaign_recipients');
            DB::statement('DROP TRIGGER IF EXISTS recipient_import_frozen_experiment_insert_guard ON recipient_imports');
            DB::statement('DROP FUNCTION IF EXISTS guard_frozen_experiment_audience()');

            return;
        }

        foreach ($this->triggerNames() as $trigger) {
            DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
        }
    }

    /** @return list<string> */
    private function triggerNames(): array
    {
        return [
            'campaign_recipient_frozen_experiment_insert_guard',
            'campaign_recipient_frozen_experiment_update_guard',
            'recipient_import_frozen_experiment_insert_guard',
            'recipient_import_frozen_experiment_update_guard',
        ];
    }

    private function createSqliteTriggers(): void
    {
        foreach ([
            'campaign_recipient_frozen_experiment_insert_guard' => 'campaign_recipients',
            'recipient_import_frozen_experiment_insert_guard' => 'recipient_imports',
        ] as $trigger => $table) {
            DB::statement(<<<SQL
CREATE TRIGGER {$trigger}
BEFORE INSERT ON {$table}
FOR EACH ROW
WHEN EXISTS (
    SELECT 1
    FROM campaigns campaign
    INNER JOIN mail_experiment_cohorts cohort
        ON cohort.campaign_id = campaign.id
        OR cohort.id = campaign.mail_experiment_cohort_id
    INNER JOIN mail_experiments experiment
        ON experiment.id = cohort.mail_experiment_id
    WHERE campaign.id = NEW.campaign_id
      AND (
          experiment.frozen_at IS NOT NULL
          OR experiment.assignment_snapshot_hash IS NOT NULL
          OR experiment.status IN ('running', 'paused', 'completed', 'aborted')
      )
)
BEGIN
    SELECT RAISE(ABORT, 'A frozen mail experiment cohort audience is immutable');
END
SQL);
        }

        foreach ([
            'campaign_recipient_frozen_experiment_update_guard' => 'campaign_recipients',
            'recipient_import_frozen_experiment_update_guard' => 'recipient_imports',
        ] as $trigger => $table) {
            DB::statement(<<<SQL
CREATE TRIGGER {$trigger}
BEFORE UPDATE ON {$table}
FOR EACH ROW
WHEN NEW.campaign_id IS NOT OLD.campaign_id
 AND EXISTS (
    SELECT 1
    FROM campaigns campaign
    INNER JOIN mail_experiment_cohorts cohort
        ON cohort.campaign_id = campaign.id
        OR cohort.id = campaign.mail_experiment_cohort_id
    INNER JOIN mail_experiments experiment
        ON experiment.id = cohort.mail_experiment_id
    WHERE campaign.id = NEW.campaign_id
      AND (
          experiment.frozen_at IS NOT NULL
          OR experiment.assignment_snapshot_hash IS NOT NULL
          OR experiment.status IN ('running', 'paused', 'completed', 'aborted')
      )
)
BEGIN
    SELECT RAISE(ABORT, 'A frozen mail experiment cohort audience is immutable');
END
SQL);
        }
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER campaign_recipient_frozen_experiment_insert_guard
BEFORE INSERT ON campaign_recipients
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM campaigns campaign
        INNER JOIN mail_experiment_cohorts cohort
            ON cohort.campaign_id = campaign.id
            OR cohort.id = campaign.mail_experiment_cohort_id
        INNER JOIN mail_experiments experiment
            ON experiment.id = cohort.mail_experiment_id
        WHERE campaign.id = NEW.campaign_id
          AND (
              experiment.frozen_at IS NOT NULL
              OR experiment.assignment_snapshot_hash IS NOT NULL
              OR experiment.status IN ('running', 'paused', 'completed', 'aborted')
          )
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A frozen mail experiment cohort audience is immutable';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER campaign_recipient_frozen_experiment_update_guard
BEFORE UPDATE ON campaign_recipients
FOR EACH ROW
BEGIN
    IF NOT (NEW.campaign_id <=> OLD.campaign_id)
       AND EXISTS (
        SELECT 1
        FROM campaigns campaign
        INNER JOIN mail_experiment_cohorts cohort
            ON cohort.campaign_id = campaign.id
            OR cohort.id = campaign.mail_experiment_cohort_id
        INNER JOIN mail_experiments experiment
            ON experiment.id = cohort.mail_experiment_id
        WHERE campaign.id = NEW.campaign_id
          AND (
              experiment.frozen_at IS NOT NULL
              OR experiment.assignment_snapshot_hash IS NOT NULL
              OR experiment.status IN ('running', 'paused', 'completed', 'aborted')
          )
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A frozen mail experiment cohort audience is immutable';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER recipient_import_frozen_experiment_insert_guard
BEFORE INSERT ON recipient_imports
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM campaigns campaign
        INNER JOIN mail_experiment_cohorts cohort
            ON cohort.campaign_id = campaign.id
            OR cohort.id = campaign.mail_experiment_cohort_id
        INNER JOIN mail_experiments experiment
            ON experiment.id = cohort.mail_experiment_id
        WHERE campaign.id = NEW.campaign_id
          AND (
              experiment.frozen_at IS NOT NULL
              OR experiment.assignment_snapshot_hash IS NOT NULL
              OR experiment.status IN ('running', 'paused', 'completed', 'aborted')
          )
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A frozen mail experiment cohort audience is immutable';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER recipient_import_frozen_experiment_update_guard
BEFORE UPDATE ON recipient_imports
FOR EACH ROW
BEGIN
    IF NOT (NEW.campaign_id <=> OLD.campaign_id)
       AND EXISTS (
        SELECT 1
        FROM campaigns campaign
        INNER JOIN mail_experiment_cohorts cohort
            ON cohort.campaign_id = campaign.id
            OR cohort.id = campaign.mail_experiment_cohort_id
        INNER JOIN mail_experiments experiment
            ON experiment.id = cohort.mail_experiment_id
        WHERE campaign.id = NEW.campaign_id
          AND (
              experiment.frozen_at IS NOT NULL
              OR experiment.assignment_snapshot_hash IS NOT NULL
              OR experiment.status IN ('running', 'paused', 'completed', 'aborted')
          )
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A frozen mail experiment cohort audience is immutable';
    END IF;
END
SQL);
    }

    private function createPostgresTriggers(): void
    {
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_frozen_experiment_audience()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
DECLARE
    target_campaign_id bigint;
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.campaign_id IS NOT DISTINCT FROM OLD.campaign_id THEN
        RETURN NEW;
    END IF;

    target_campaign_id := NEW.campaign_id;
    IF EXISTS (
        SELECT 1
        FROM campaigns campaign
        INNER JOIN mail_experiment_cohorts cohort
            ON cohort.campaign_id = campaign.id
            OR cohort.id = campaign.mail_experiment_cohort_id
        INNER JOIN mail_experiments experiment
            ON experiment.id = cohort.mail_experiment_id
        WHERE campaign.id = target_campaign_id
          AND (
              experiment.frozen_at IS NOT NULL
              OR experiment.assignment_snapshot_hash IS NOT NULL
              OR experiment.status IN ('running', 'paused', 'completed', 'aborted')
          )
    ) THEN
        RAISE EXCEPTION 'A frozen mail experiment cohort audience is immutable';
    END IF;

    RETURN NEW;
END;
$function$
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER campaign_recipient_frozen_experiment_insert_guard
BEFORE INSERT OR UPDATE ON campaign_recipients
FOR EACH ROW EXECUTE FUNCTION guard_frozen_experiment_audience()
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER recipient_import_frozen_experiment_insert_guard
BEFORE INSERT OR UPDATE ON recipient_imports
FOR EACH ROW EXECUTE FUNCTION guard_frozen_experiment_audience()
SQL);
    }
};
