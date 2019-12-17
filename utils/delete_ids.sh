#!/bin/bash

# A simple utility script that deletes records from Solr using an ID list file
# containing one record ID per line.
# Doesn't escape the IDs, so make sure the input file is clean.

if [ -z "$2" ]; then
  # Usage
  echo 'Usage: delete_ids.sh <solr update url> <idfile>'
  exit 1;
fi

SOLR_URL=$1
IDFILE=$2

IDLIST=""
IDCOUNT=0
LISTCOUNT=0

while read -r line; do
  IDCOUNT=$[$IDCOUNT+1]
  LISTCOUNT=$[$LISTCOUNT+1]
  IDLIST="${IDLIST}<id>$line</id>"
  if [ ${LISTCOUNT} -ge 1000 ]; then
    curl -X POST ${SOLR_URL} -H "Content-Type: text/xml" --data-binary "<delete>${IDLIST}</delete>"
    IDLIST=""
    LISTCOUNT=0
    echo "${IDCOUNT} IDs deleted"
  fi
done < "${IDFILE}"

if [ ${#IDLIST} -gt 0 ]; then
  curl -X POST ${SOLR_URL} -H "Content-Type: text/xml" --data-binary "<delete>${IDLIST}</delete>"
  IDLIST=""
  echo "${IDCOUNT} IDs deleted"
fi

echo "All done."
