include ../../PluginsMakefile.mk

test: phpunit phpcsfixer-check phpstan psalm  ## Run all our lints/tests/static analysis
.PHONY: test
