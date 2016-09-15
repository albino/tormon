create table users (
  id integer primary key,
  email text not null,
  confirmed integer not null,
  fp text not null,
  secret text,
  /*
  status
  0 - everything is fine
  1 - the relay was down
  2 - the relay has gone!
  */
  status integer not null
);
