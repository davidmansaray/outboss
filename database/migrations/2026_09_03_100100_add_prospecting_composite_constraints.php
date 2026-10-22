<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prospect_source_versions', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'prospect_source_id'],
                'prospect_source_versions_workspace_source_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('prospect_sources')
                ->restrictOnDelete();
        });

        Schema::table('source_runs', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'prospect_source_id'],
                'source_runs_workspace_source_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('prospect_sources')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'prospect_source_id', 'prospect_source_version_id'],
                'source_runs_workspace_source_version_fk',
            )
                ->references(['workspace_id', 'prospect_source_id', 'id'])
                ->on('prospect_source_versions')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'prospect_source_id', 'retry_of_run_id'],
                'source_runs_retry_run_fk',
            )
                ->references(['workspace_id', 'prospect_source_id', 'id'])
                ->on('source_runs')
                ->restrictOnDelete();
        });

        Schema::table('source_observations', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'prospect_source_id'],
                'source_observations_workspace_source_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('prospect_sources')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'prospect_source_id', 'prospect_source_version_id'],
                'source_observations_workspace_source_version_fk',
            )
                ->references(['workspace_id', 'prospect_source_id', 'id'])
                ->on('prospect_source_versions')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'prospect_source_id', 'source_run_id'],
                'source_observations_workspace_source_run_fk',
            )
                ->references(['workspace_id', 'prospect_source_id', 'id'])
                ->on('source_runs')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'account_id'],
                'source_observations_workspace_account_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('accounts')
                // workspace_id is required by the tenant boundary, so a
                // composite SET NULL action is not valid. The migration's
                // parent-delete trigger clears account_id first, preserving
                // the source observation while keeping this FK restrictive.
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'person_id'],
                'source_observations_workspace_person_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('people')
                ->restrictOnDelete();
        });

        Schema::table('prospect_sources', function (Blueprint $table): void {
            $table->foreign(['id', 'current_version_id'], 'prospect_sources_current_version_fk')
                ->references(['prospect_source_id', 'id'])
                ->on('prospect_source_versions')
                ->restrictOnDelete();
            $table->foreign(['id', 'last_run_id'], 'prospect_sources_last_run_fk')
                ->references(['prospect_source_id', 'id'])
                ->on('source_runs')
                ->restrictOnDelete();
        });

        Schema::table('account_source_memberships', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'prospect_source_id'],
                'account_memberships_workspace_source_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('prospect_sources')
                ->restrictOnDelete();
            $table->foreign(['workspace_id', 'account_id'], 'account_memberships_workspace_account_fk')
                ->references(['workspace_id', 'id'])
                ->on('accounts')
                ->cascadeOnDelete();
            $table->foreign(
                ['workspace_id', 'prospect_source_id', 'first_source_run_id'],
                'account_memberships_first_run_fk',
            )
                ->references(['workspace_id', 'prospect_source_id', 'id'])
                ->on('source_runs')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'prospect_source_id', 'last_source_run_id'],
                'account_memberships_last_run_fk',
            )
                ->references(['workspace_id', 'prospect_source_id', 'id'])
                ->on('source_runs')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'prospect_source_id', 'first_source_observation_id'],
                'account_memberships_first_observation_fk',
            )
                ->references(['workspace_id', 'prospect_source_id', 'id'])
                ->on('source_observations')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'prospect_source_id', 'last_source_observation_id'],
                'account_memberships_last_observation_fk',
            )
                ->references(['workspace_id', 'prospect_source_id', 'id'])
                ->on('source_observations')
                ->restrictOnDelete();
        });

        Schema::table('person_source_memberships', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'prospect_source_id'],
                'person_memberships_workspace_source_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('prospect_sources')
                ->restrictOnDelete();
            $table->foreign(['workspace_id', 'person_id'], 'person_memberships_workspace_person_fk')
                ->references(['workspace_id', 'id'])
                ->on('people')
                ->cascadeOnDelete();
            $table->foreign(
                ['workspace_id', 'prospect_source_id', 'first_source_run_id'],
                'person_memberships_first_run_fk',
            )
                ->references(['workspace_id', 'prospect_source_id', 'id'])
                ->on('source_runs')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'prospect_source_id', 'last_source_run_id'],
                'person_memberships_last_run_fk',
            )
                ->references(['workspace_id', 'prospect_source_id', 'id'])
                ->on('source_runs')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'prospect_source_id', 'first_source_observation_id'],
                'person_memberships_first_observation_fk',
            )
                ->references(['workspace_id', 'prospect_source_id', 'id'])
                ->on('source_observations')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'prospect_source_id', 'last_source_observation_id'],
                'person_memberships_last_observation_fk',
            )
                ->references(['workspace_id', 'prospect_source_id', 'id'])
                ->on('source_observations')
                ->restrictOnDelete();
        });

        Schema::table('external_identities', function (Blueprint $table): void {
            $table->foreign(
                ['workspace_id', 'first_source_observation_id'],
                'external_identities_first_observation_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('source_observations')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'last_source_observation_id'],
                'external_identities_last_observation_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('source_observations')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'account_id'],
                'external_identities_workspace_account_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('accounts')
                ->cascadeOnDelete();
            $table->foreign(
                ['workspace_id', 'person_id'],
                'external_identities_workspace_person_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('people')
                ->cascadeOnDelete();
        });

        Schema::table('canonical_field_provenance', function (Blueprint $table): void {
            $table->foreign(['workspace_id', 'source_observation_id'], 'canonical_provenance_workspace_observation_fk')
                ->references(['workspace_id', 'id'])
                ->on('source_observations')
                ->restrictOnDelete();
            $table->foreign(['workspace_id', 'source_run_id'], 'canonical_provenance_workspace_run_fk')
                ->references(['workspace_id', 'id'])
                ->on('source_runs')
                ->restrictOnDelete();
            $table->foreign(
                ['workspace_id', 'account_id'],
                'canonical_provenance_workspace_account_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('accounts')
                ->cascadeOnDelete();
            $table->foreign(
                ['workspace_id', 'person_id'],
                'canonical_provenance_workspace_person_fk',
            )
                ->references(['workspace_id', 'id'])
                ->on('people')
                ->cascadeOnDelete();
        });

        if (DB::getDriverName() === 'sqlite') {
            $this->addSqliteChecks();
        } elseif (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->addMysqlChecks();
        }
    }

    private function addMysqlChecks(): void
    {
        DB::unprepared('CREATE TRIGGER source_observations_account_delete BEFORE DELETE ON accounts FOR EACH ROW BEGIN UPDATE source_observations SET account_id = NULL WHERE workspace_id = OLD.workspace_id AND account_id = OLD.id; END');
        DB::unprepared('CREATE TRIGGER source_observations_person_delete BEFORE DELETE ON people FOR EACH ROW BEGIN UPDATE source_observations SET person_id = NULL WHERE workspace_id = OLD.workspace_id AND person_id = OLD.id; END');

        foreach ([
            'source_observations' => "((NEW.entity_type = 'account' AND NEW.account_id IS NOT NULL AND NEW.person_id IS NULL) OR (NEW.entity_type = 'person' AND NEW.person_id IS NOT NULL AND NEW.account_id IS NULL) OR (NEW.account_id IS NULL AND NEW.person_id IS NULL))",
            'external_identities' => "((NEW.entity_type = 'account' AND NEW.account_id IS NOT NULL AND NEW.person_id IS NULL) OR (NEW.entity_type = 'person' AND NEW.person_id IS NOT NULL AND NEW.account_id IS NULL))",
            'canonical_field_provenance' => "((NEW.entity_type = 'account' AND NEW.account_id IS NOT NULL AND NEW.person_id IS NULL) OR (NEW.entity_type = 'person' AND NEW.person_id IS NOT NULL AND NEW.account_id IS NULL))",
        ] as $table => $condition) {
            $name = $table.'_entity_reference_insert';
            DB::unprepared("CREATE TRIGGER {$name} BEFORE INSERT ON {$table} FOR EACH ROW BEGIN IF NOT {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table}_entity_reference_check'; END IF; END");
            $name = $table.'_entity_reference_update';
            DB::unprepared("CREATE TRIGGER {$name} BEFORE UPDATE ON {$table} FOR EACH ROW BEGIN IF NOT {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$table}_entity_reference_check'; END IF; END");
        }
    }

    private function addSqliteChecks(): void
    {
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER source_observations_account_delete
            BEFORE DELETE ON accounts
            FOR EACH ROW
            BEGIN
                UPDATE source_observations
                SET account_id = NULL
                WHERE workspace_id = OLD.workspace_id
                  AND account_id = OLD.id;
            END;
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER source_observations_person_delete
            BEFORE DELETE ON people
            FOR EACH ROW
            BEGIN
                UPDATE source_observations
                SET person_id = NULL
                WHERE workspace_id = OLD.workspace_id
                  AND person_id = OLD.id;
            END;
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER source_observations_entity_reference_insert
            BEFORE INSERT ON source_observations
            WHEN NOT (
                (NEW.entity_type = 'account' AND NEW.account_id IS NOT NULL AND NEW.person_id IS NULL)
                OR (NEW.entity_type = 'person' AND NEW.person_id IS NOT NULL AND NEW.account_id IS NULL)
                OR (NEW.account_id IS NULL AND NEW.person_id IS NULL)
            )
            BEGIN SELECT RAISE(ABORT, 'source_observations_entity_reference_check'); END;
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER source_observations_entity_reference_update
            BEFORE UPDATE ON source_observations
            WHEN NOT (
                (NEW.entity_type = 'account' AND NEW.account_id IS NOT NULL AND NEW.person_id IS NULL)
                OR (NEW.entity_type = 'person' AND NEW.person_id IS NOT NULL AND NEW.account_id IS NULL)
                OR (NEW.account_id IS NULL AND NEW.person_id IS NULL)
            )
            BEGIN SELECT RAISE(ABORT, 'source_observations_entity_reference_check'); END;
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER external_identities_entity_reference_insert
            BEFORE INSERT ON external_identities
            WHEN NOT (
                (NEW.entity_type = 'account' AND NEW.account_id IS NOT NULL AND NEW.person_id IS NULL)
                OR (NEW.entity_type = 'person' AND NEW.person_id IS NOT NULL AND NEW.account_id IS NULL)
            )
            BEGIN SELECT RAISE(ABORT, 'external_identities_entity_reference_check'); END;
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER external_identities_entity_reference_update
            BEFORE UPDATE ON external_identities
            WHEN NOT (
                (NEW.entity_type = 'account' AND NEW.account_id IS NOT NULL AND NEW.person_id IS NULL)
                OR (NEW.entity_type = 'person' AND NEW.person_id IS NOT NULL AND NEW.account_id IS NULL)
            )
            BEGIN SELECT RAISE(ABORT, 'external_identities_entity_reference_check'); END;
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER canonical_field_provenance_entity_reference_insert
            BEFORE INSERT ON canonical_field_provenance
            WHEN NOT (
                (NEW.entity_type = 'account' AND NEW.account_id IS NOT NULL AND NEW.person_id IS NULL)
                OR (NEW.entity_type = 'person' AND NEW.person_id IS NOT NULL AND NEW.account_id IS NULL)
            )
            BEGIN SELECT RAISE(ABORT, 'canonical_field_provenance_entity_reference_check'); END;
        SQL);
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER canonical_field_provenance_entity_reference_update
            BEFORE UPDATE ON canonical_field_provenance
            WHEN NOT (
                (NEW.entity_type = 'account' AND NEW.account_id IS NOT NULL AND NEW.person_id IS NULL)
                OR (NEW.entity_type = 'person' AND NEW.person_id IS NOT NULL AND NEW.account_id IS NULL)
            )
            BEGIN SELECT RAISE(ABORT, 'canonical_field_provenance_entity_reference_check'); END;
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            $this->dropEntityReferenceTriggers();

            // SQLite stores foreign keys in the table definition and cannot
            // drop one by its MySQL-style constraint name. Supplying the
            // constrained columns lets Laravel rebuild each table while
            // retaining every unrelated key. Keep this migration reversible
            // on its own; callers may roll back just the composite layer
            // before deciding whether to remove the lineage tables.
            Schema::table('canonical_field_provenance', function (Blueprint $table): void {
                $table->dropForeign(['workspace_id', 'person_id']);
                $table->dropForeign(['workspace_id', 'account_id']);
                $table->dropForeign(['workspace_id', 'source_run_id']);
                $table->dropForeign(['workspace_id', 'source_observation_id']);
            });
            Schema::table('external_identities', function (Blueprint $table): void {
                $table->dropForeign(['workspace_id', 'person_id']);
                $table->dropForeign(['workspace_id', 'account_id']);
                $table->dropForeign(['workspace_id', 'last_source_observation_id']);
                $table->dropForeign(['workspace_id', 'first_source_observation_id']);
            });
            Schema::table('person_source_memberships', function (Blueprint $table): void {
                foreach ([
                    ['workspace_id', 'prospect_source_id', 'last_source_observation_id'],
                    ['workspace_id', 'prospect_source_id', 'first_source_observation_id'],
                    ['workspace_id', 'prospect_source_id', 'last_source_run_id'],
                    ['workspace_id', 'prospect_source_id', 'first_source_run_id'],
                    ['workspace_id', 'person_id'],
                    ['workspace_id', 'prospect_source_id'],
                ] as $columns) {
                    $table->dropForeign($columns);
                }
            });
            Schema::table('account_source_memberships', function (Blueprint $table): void {
                foreach ([
                    ['workspace_id', 'prospect_source_id', 'last_source_observation_id'],
                    ['workspace_id', 'prospect_source_id', 'first_source_observation_id'],
                    ['workspace_id', 'prospect_source_id', 'last_source_run_id'],
                    ['workspace_id', 'prospect_source_id', 'first_source_run_id'],
                    ['workspace_id', 'account_id'],
                    ['workspace_id', 'prospect_source_id'],
                ] as $columns) {
                    $table->dropForeign($columns);
                }
            });
            Schema::table('prospect_sources', function (Blueprint $table): void {
                $table->dropForeign(['id', 'last_run_id']);
                $table->dropForeign(['id', 'current_version_id']);
            });
            Schema::table('source_observations', function (Blueprint $table): void {
                $table->dropForeign(['workspace_id', 'person_id']);
                $table->dropForeign(['workspace_id', 'account_id']);
                $table->dropForeign(['workspace_id', 'prospect_source_id', 'source_run_id']);
                $table->dropForeign(['workspace_id', 'prospect_source_id', 'prospect_source_version_id']);
                $table->dropForeign(['workspace_id', 'prospect_source_id']);
            });
            Schema::table('source_runs', function (Blueprint $table): void {
                $table->dropForeign(['workspace_id', 'prospect_source_id', 'retry_of_run_id']);
                $table->dropForeign(['workspace_id', 'prospect_source_id', 'prospect_source_version_id']);
                $table->dropForeign(['workspace_id', 'prospect_source_id']);
            });
            Schema::table('prospect_source_versions', function (Blueprint $table): void {
                $table->dropForeign(['workspace_id', 'prospect_source_id']);
            });

            return;
        }
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            $this->dropEntityReferenceTriggers();
        }

        Schema::table('canonical_field_provenance', function (Blueprint $table): void {
            $table->dropForeign('canonical_provenance_workspace_person_fk');
            $table->dropForeign('canonical_provenance_workspace_account_fk');
            $table->dropForeign('canonical_provenance_workspace_run_fk');
            $table->dropForeign('canonical_provenance_workspace_observation_fk');
        });
        Schema::table('external_identities', function (Blueprint $table): void {
            $table->dropForeign('external_identities_workspace_person_fk');
            $table->dropForeign('external_identities_workspace_account_fk');
            $table->dropForeign('external_identities_last_observation_fk');
            $table->dropForeign('external_identities_first_observation_fk');
        });
        Schema::table('person_source_memberships', function (Blueprint $table): void {
            foreach ([
                'person_memberships_last_observation_fk',
                'person_memberships_first_observation_fk',
                'person_memberships_last_run_fk',
                'person_memberships_first_run_fk',
                'person_memberships_workspace_person_fk',
                'person_memberships_workspace_source_fk',
            ] as $foreign) {
                $table->dropForeign($foreign);
            }
        });
        Schema::table('account_source_memberships', function (Blueprint $table): void {
            foreach ([
                'account_memberships_last_observation_fk',
                'account_memberships_first_observation_fk',
                'account_memberships_last_run_fk',
                'account_memberships_first_run_fk',
                'account_memberships_workspace_account_fk',
                'account_memberships_workspace_source_fk',
            ] as $foreign) {
                $table->dropForeign($foreign);
            }
        });
        Schema::table('prospect_sources', function (Blueprint $table): void {
            $table->dropForeign('prospect_sources_last_run_fk');
            $table->dropForeign('prospect_sources_current_version_fk');
        });
        Schema::table('source_observations', function (Blueprint $table): void {
            $table->dropForeign('source_observations_workspace_person_fk');
            $table->dropForeign('source_observations_workspace_account_fk');
            $table->dropForeign('source_observations_workspace_source_run_fk');
            $table->dropForeign('source_observations_workspace_source_version_fk');
            $table->dropForeign('source_observations_workspace_source_fk');
        });
        Schema::table('source_runs', function (Blueprint $table): void {
            $table->dropForeign('source_runs_retry_run_fk');
            $table->dropForeign('source_runs_workspace_source_version_fk');
            $table->dropForeign('source_runs_workspace_source_fk');
        });
        Schema::table('prospect_source_versions', function (Blueprint $table): void {
            $table->dropForeign('prospect_source_versions_workspace_source_fk');
        });
    }

    private function dropEntityReferenceTriggers(): void
    {
        foreach ([
            'source_observations_person_delete',
            'source_observations_account_delete',
            'canonical_field_provenance_entity_reference_update',
            'canonical_field_provenance_entity_reference_insert',
            'external_identities_entity_reference_update',
            'external_identities_entity_reference_insert',
            'source_observations_entity_reference_update',
            'source_observations_entity_reference_insert',
        ] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
    }
};
