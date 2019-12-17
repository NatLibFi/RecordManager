#!/bin/bash

# A simple utility script that deletes records from Solr using an ID list file
# containing one record ID per line.
# Doesn't escape the IDs, so make sure the input file is clean.

if [ -z "$2" ]; then
  # Usage
  echo 'Usage: delete_ids.sh <solr host> <collection> <idfile>'
  exit 1;
fi

SOLR=$1
COLLECTION=$2
IDFILE=$3

IDLIST=""
IDCOUNT=0

while read -r line; do
  IDCOUNT=$[$IDCOUNT+1]
  IDLIST="${IDLIST}<id>$line</id>"
  if [ ${#IDLIST} -gt 65535 ]; then
    curl -X POST "http://${SOLR}/solr/${COLLECTION}/update" -H "Content-Type: text/xml" --data-binary "<delete>${IDLIST}</delete>"
    IDLIST=""
    echo "${IDCOUNT} IDs deleted"
  fi
done < "${IDFILE}"

if [ ${#IDLIST} -gt 0 ]; then
  curl -X POST "http://${SOLR}/solr/${COLLECTION}/update" -H "Content-Type: text/xml" --data-binary "<delete>${IDLIST}</delete>"
  IDLIST=""
  echo "${IDCOUNT} IDs deleted"
fi

echo "All done."
