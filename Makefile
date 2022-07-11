#!/bin/bash

UID = 1000
#UID = $(shell id -u)
DOCKER_BE = test-app

help: ## Show this help message
	@echo 'usage: make [target]'
	@echo
	@echo 'targets:'
	@egrep '^(.+)\:\ ##\ (.+)' ${MAKEFILE_LIST} | column -t -c 2 -s ':#'

ELASTICSEARCH_INSTALL_OPTIONS=" --search-engine=elasticsearch7 --elasticsearch-host=elasticsearch_magento2_m2 --elasticsearch-port=9200"

MAGENTO_INSTALL_OPTIONS=--db-host=db   --db-name=dbname   --db-user=root   --db-password=test   --base-url=http://localhost:81/   --admin-firstname=MyName   --admin-lastname=LastName   --admin-email=admin@admin.com   --admin-user=admin   --admin-password=password1234   --backend-frontname=admin   --language=en_US   --currency=USD   --use-rewrites=1   --use-secure=1   --use-secure-admin=1   --timezone=Europe/Madrid

start: ## Start the containers
	docker network create test-network || true
	cp -n docker-compose.yml.dist docker-compose.yml || true
	U_ID=${UID} docker-compose up -d

stop: ## Stop the containers
	U_ID=${UID} docker-compose stop

restart: ## Restart the containers
	$(MAKE) stop && $(MAKE) start

build: ## Rebuilds all the containers
	docker network create test-network || true
	cp -n docker-compose.yml.dist docker-compose.yml || true
	U_ID=${UID} docker-compose build

prepare: ## Runs backend commands
	$(MAKE) composer-install

run: ## starts the test development server in detached mode
	U_ID=${UID} docker exec -it --user ${UID} ${DOCKER_BE} test serve -d

logs: ## Show test logs in real time
	U_ID=${UID} docker exec -it --user ${UID} ${DOCKER_BE} test server:log

# Backend commands
composer-install: ## Installs composer dependencies
    #U_ID=${UID} docker exec --user ${UID} ${DOCKER_BE} composer install --no-interaction
    U_ID=${UID} docker exec --user ${UID} ${DOCKER_BE} composer create-project --repository-url=https://repo.magento.com/ magento/project-community-edition=2.3.* .

magento-install: ## Install magento proyect
    U_ID=${UID} docker exec --user ${UID} ${DOCKER_BE} git clone git@github.com:magento/magento2.git
#U_ID=${UID} docker exec -it --user ${UID} ${DOCKER_BE} bash bin/magento setup:install ${MAGENTO_INSTALL_OPTIONS}

# End backend commands
magento-setup: ## Installs composer dependencies
    U_ID=${UID} docker exec -it --user ${UID} ${DOCKER_BE} bash bin/magento sampledata:deploy
    U_ID=${UID} docker exec -it --user ${UID} ${DOCKER_BE} bash bin/magento setup:upgrade
    U_ID=${UID} docker exec -it --user ${UID} ${DOCKER_BE} bash bin/magento setup:di:compile
    U_ID=${UID} docker exec -it --user ${UID} ${DOCKER_BE} bash bin/magento deploy:mode:set developer
    U_ID=${UID} docker exec -it --user ${UID} ${DOCKER_BE} bash bin/magento indexer:reindex
    U_ID=${UID} docker exec -it --user ${UID} ${DOCKER_BE} bash bin/magento setup:static-content:deploy -f
    U_ID=${UID} docker exec -it --user ${UID} ${DOCKER_BE} bash bin/magento cache:clean
    U_ID=${UID} docker exec -it --user ${UID} ${DOCKER_BE} bash bin/magento cache:flush
ssh-be: ## bash into the be container
	U_ID=${UID} docker exec -it --user ${UID} ${DOCKER_BE} bash
