create table users (
  id int primary key not null,
  email text not null,
  confirmed int not null,
  subscriptions text
);
