#!/bin/bash

# create .env from example
echo "Create .env from example"
if [ ! -f ./.env ]; then
    echo "File .env doesn't exist. Recreating..."
    cp ./local-dev/templates/.env.template ./.env && echo "Ok."
else
    echo "File .env already exists."
fi

# import variables from .env file
. ./.env
