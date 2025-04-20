#!/bin/bash

# create .env from example
echo "Create .env from example"
if [ ! -f ./local-dev/.env ]; then
    echo "File .env doesn't exist. Recreating..."
    cp ./local-dev/templates/.env.template ./local-dev/.env && echo "Ok."
else
    echo "File .env already exists."
fi

# import variables from .env file
. ./local-dev/.env
