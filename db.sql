create table users (
  id integer primary key,
  email text not null,
  confirmed integer not null,
  fp text not null,
  secret text
);
