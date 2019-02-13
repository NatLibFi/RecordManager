 #!/bin/bash -e

if [ "$3" == "" ]; then
  echo "Usage: $0 <SPARQL endpoint url> <Mongo database> <Mongo collection>"
  echo
  echo "Updates the ontology enrichment collection with data retrieved from a SPARQL server"
  echo
  echo "Example: $0 http://api.finto.fi/sparql recman ontologyEnrichment"
  echo
  exit
fi

DIR=$(dirname "$0")
URL=$1
DATABASE=$2
COLLECTION=$3

curl -s -H "Accept: text/csv" --data @${DIR}/fetch_yso.post ${URL} > yso.csv
mongoimport --quiet -d ${DATABASE} -c ${COLLECTION} --file yso.csv --type=csv --headerline --mode=upsert

curl -s -H "Accept: text/csv" --data @${DIR}/fetch_ysa.post ${URL} > ysa.csv
mongoimport --quiet -d ${DATABASE} -c ${COLLECTION} --file ysa.csv --type=csv --headerline --mode=upsert

curl -s -H "Accept: text/csv" --data @${DIR}/fetch_allars.post ${URL} > allars.csv
mongoimport --quiet -d ${DATABASE} -c ${COLLECTION} --file allars.csv --type=csv --headerline --mode=upsert
