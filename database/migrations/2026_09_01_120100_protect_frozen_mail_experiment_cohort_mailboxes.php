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
            $this->createPostgresTrigger();
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            foreach ($this->triggerNames() as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
            }

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            foreach ($this->triggerNames() as $trigger) {
                DB::statement('DROP TRIGGER IF EXISTS '.$trigger);
            }

            return;
        }

        if ($driver === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS mail_experiment_cohort_mailbox_guard ON mail_experiment_cohort_mailbox');
            DB::statement('DROP FUNCTION IF EXISTS guard_mail_experiment_cohort_mailbox()');
        }
    }

    /** @return list<string> */
    private function triggerNames(): array
    {
        return [
            'mail_experiment_cohort_mailbox_insert_guard',
            'mail_experiment_cohort_mailbox_update_guard',
            'mail_experiment_cohort_mailbox_delete_guard',
            'mail_experiment_cohort_mailbox_insert_consistency_guard',
        ];
    }

    private function createSqliteTriggers(): void
    {
        DB::statement(<<<'SQL'
CREATE TRIGGER mail_experiment_cohort_mailbox_insert_guard
BEFORE INSERT ON mail_experiment_cohort_mailbox
FOR EACH ROW
WHEN EXISTS (
    SELECT 1
    FROM mail_experiment_cohorts c
    INNER JOIN mail_experiments e ON e.id = c.mail_experiment_id
    WHERE c.id = NEW.mail_experiment_cohort_id
      AND e.frozen_at IS NOT NULL
)
BEGIN
    SELECT RAISE(ABORT, 'A frozen mail experiment cohort mailbox topology is immutable');
END
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER mail_experiment_cohort_mailbox_update_guard
BEFORE UPDATE ON mail_experiment_cohort_mailbox
FOR EACH ROW
WHEN EXISTS (
    SELECT 1
    FROM mail_experiment_cohorts c
    INNER JOIN mail_experiments e ON e.id = c.mail_experiment_id
    WHERE c.id IN (OLD.mail_experiment_cohort_id, NEW.mail_experiment_cohort_id)
      AND e.frozen_at IS NOT NULL
)
   OR NEW.mail_experiment_id <> (
       SELECT c.mail_experiment_id
       FROM mail_experiment_cohorts c
       WHERE c.id = NEW.mail_experiment_cohort_id
   )
BEGIN
    SELECT RAISE(ABORT, 'A frozen mail experiment cohort mailbox topology is immutable');
END
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER mail_experiment_cohort_mailbox_delete_guard
BEFORE DELETE ON mail_experiment_cohort_mailbox
FOR EACH ROW
WHEN EXISTS (
    SELECT 1
    FROM mail_experiment_cohorts c
    INNER JOIN mail_experiments e ON e.id = c.mail_experiment_id
    WHERE c.id = OLD.mail_experiment_cohort_id
      AND e.frozen_at IS NOT NULL
)
BEGIN
    SELECT RAISE(ABORT, 'A frozen mail experiment cohort mailbox topology is immutable');
END
SQL);

        // A relation attach normally fills this denormalised value through
        // the custom pivot model.  Keep the same invariant for raw SQL too.
        DB::statement(<<<'SQL'
CREATE TRIGGER mail_experiment_cohort_mailbox_insert_consistency_guard
BEFORE INSERT ON mail_experiment_cohort_mailbox
FOR EACH ROW
WHEN NEW.mail_experiment_id <> (
    SELECT c.mail_experiment_id
    FROM mail_experiment_cohorts c
    WHERE c.id = NEW.mail_experiment_cohort_id
)
BEGIN
    SELECT RAISE(ABORT, 'The cohort mailbox experiment does not match its cohort');
END
SQL);
    }

    private function createMysqlTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TRIGGER mail_experiment_cohort_mailbox_insert_guard
BEFORE INSERT ON mail_experiment_cohort_mailbox
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM mail_experiment_cohorts c
        INNER JOIN mail_experiments e ON e.id = c.mail_experiment_id
        WHERE c.id = NEW.mail_experiment_cohort_id
          AND e.frozen_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A frozen mail experiment cohort mailbox topology is immutable';
    END IF;
    IF NEW.mail_experiment_id <> (
        SELECT c.mail_experiment_id
        FROM mail_experiment_cohorts c
        WHERE c.id = NEW.mail_experiment_cohort_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The cohort mailbox experiment does not match its cohort';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER mail_experiment_cohort_mailbox_update_guard
BEFORE UPDATE ON mail_experiment_cohort_mailbox
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM mail_experiment_cohorts c
        INNER JOIN mail_experiments e ON e.id = c.mail_experiment_id
        WHERE c.id IN (OLD.mail_experiment_cohort_id, NEW.mail_experiment_cohort_id)
          AND e.frozen_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A frozen mail experiment cohort mailbox topology is immutable';
    END IF;
    IF NEW.mail_experiment_id <> (
        SELECT c.mail_experiment_id
        FROM mail_experiment_cohorts c
        WHERE c.id = NEW.mail_experiment_cohort_id
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The cohort mailbox experiment does not match its cohort';
    END IF;
END
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER mail_experiment_cohort_mailbox_delete_guard
BEFORE DELETE ON mail_experiment_cohort_mailbox
FOR EACH ROW
BEGIN
    IF EXISTS (
        SELECT 1
        FROM mail_experiment_cohorts c
        INNER JOIN mail_experiments e ON e.id = c.mail_experiment_id
        WHERE c.id = OLD.mail_experiment_cohort_id
          AND e.frozen_at IS NOT NULL
    ) THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A frozen mail experiment cohort mailbox topology is immutable';
    END IF;
END
SQL);
    }

    private function createPostgresTrigger(): void
    {
        DB::statement(<<<'SQL'
CREATE OR REPLACE FUNCTION guard_mail_experiment_cohort_mailbox()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
DECLARE
    cohort_experiment_id bigint;
    frozen boolean;
BEGIN
    IF TG_OP = 'DELETE' THEN
        SELECT c.mail_experiment_id, (e.frozen_at IS NOT NULL)
        INTO cohort_experiment_id, frozen
        FROM mail_experiment_cohorts c
        INNER JOIN mail_experiments e ON e.id = c.mail_experiment_id
        WHERE c.id = OLD.mail_experiment_cohort_id;
        IF frozen THEN
            RAISE EXCEPTION 'A frozen mail experiment cohort mailbox topology is immutable';
        END IF;
        RETURN OLD;
    END IF;

    SELECT c.mail_experiment_id, (e.frozen_at IS NOT NULL)
    INTO cohort_experiment_id, frozen
    FROM mail_experiment_cohorts c
    INNER JOIN mail_experiments e ON e.id = c.mail_experiment_id
    WHERE c.id = NEW.mail_experiment_cohort_id;

    IF frozen THEN
        RAISE EXCEPTION 'A frozen mail experiment cohort mailbox topology is immutable';
    END IF;

    IF TG_OP = 'UPDATE' AND EXISTS (
        SELECT 1
        FROM mail_experiment_cohorts c
        INNER JOIN mail_experiments e ON e.id = c.mail_experiment_id
        WHERE c.id = OLD.mail_experiment_cohort_id
          AND e.frozen_at IS NOT NULL
    ) THEN
        RAISE EXCEPTION 'A frozen mail experiment cohort mailbox topology is immutable';
    END IF;

    IF NEW.mail_experiment_id <> cohort_experiment_id THEN
        RAISE EXCEPTION 'The cohort mailbox experiment does not match its cohort';
    END IF;

    RETURN NEW;
END;
$function$
SQL);

        DB::statement(<<<'SQL'
CREATE TRIGGER mail_experiment_cohort_mailbox_guard
BEFORE INSERT OR UPDATE OR DELETE ON mail_experiment_cohort_mailbox
FOR EACH ROW EXECUTE FUNCTION guard_mail_experiment_cohort_mailbox()
SQL);
    }
};
