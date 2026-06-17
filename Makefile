ifeq ($(OS),Windows_NT)
    RUN_CMD = php artisan serve --port=8000
    TEST_CMD = php artisan test
else
    RUN_CMD = chmod +x scripts/run.sh && ./scripts/run.sh
    TEST_CMD = chmod +x scripts/test.sh && ./scripts/test.sh
endif

.PHONY: run test

run:
	@$(RUN_CMD)

test:
	@$(TEST_CMD)
