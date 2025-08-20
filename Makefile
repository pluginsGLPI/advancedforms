include ../../PluginsMakefile.mk

validate: phpunit phpcsfixer-check phpstan psalm  ## Run all our lints/tests/static analysis
.PHONY: validate
