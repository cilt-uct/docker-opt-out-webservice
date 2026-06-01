#! /bin/bash

branch_name=$(git rev-parse --symbolic-full-name --abbrev-ref HEAD)

read -p "Branch [$branch_name]: " branch
branch=${branch:-$branch_name}

read -p "Github Username (not email): " username

git push https://$username@github.com/cilt-uct/docker-opt-out-webservice.git $branch
