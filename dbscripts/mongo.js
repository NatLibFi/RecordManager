db.record.ensureIndex({dedup_key: 1});
db.record.ensureIndex({title_keys: 1}, {sparse: true});
db.record.ensureIndex({isbn_keys: 1}, {sparse: true});
db.record.ensureIndex({id_keys: 1}, {sparse: true});
db.record.ensureIndex({oai_id: 1});
db.record.ensureIndex({host_record_id: 1});
db.record.ensureIndex({source_id: 1});
db.record.ensureIndex({updated: 1});
