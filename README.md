# WP Connections: post-to-post connections for WordPress

wpConnections allows to link posts in WordPress by graph-like connections.
The library provides such connection properties as: 

- direction (from, to)
- from post id
- to post id
- order
- meta data.

Connections belong to a Relation and never exist out.
The relation has properties:
- cardinality (1-1, 1-m, m-1, m-m)
- from post type
- to post type
- direction type (from, to, both)
- duplicatable (whether may have same connections)
- closurable (whether may have the same post on from and to).

> There are four CPT: `magazine`, `issue`, `article` and `author`.
> Magazine posts may have connections with some Issues (one-to-many type) so that the Issues constitute the Magazine.  
> The Issues in turn have connections with Articles (one-to-many as well).
> But an Author might have been linked with many Articles, and an Article might have many connections with Authors (many-to-many).  

## Why wpConnection?

It can be used as multiple installed library being parts of different plugins in a WordPress installation.
All you need is creating a client instance for your application. Every client has its own tables and REST API hooks, and does not influence to another clients. 

## Ok, what should I do to start using?

It is available as github repo, so add this as dependency into your project using composer.json.

```
"repositories": [
    {
        "type": "vcs",
        "url": "https://github.com/hokoo/wpConnections.git"
    }
]
```

So, you have to create client instance...

```php
use iTRON\wpConnections\Client;

$wpc_client = new Client( 'my-app-wpc-client' );
```
...and relations for your connections.

```php
use iTRON\wpConnections\Query;
$qr = new Query\Relation();
$qr->set( 'name', 'post-to-page' );
$qr->set( 'from', 'post' );
$qr->set( 'to', 'page' );
$qr->set( 'cardinality', 'm-m' );

$wpc_client->registerRelation( $qr );
```

Ok, now you can create connections inside the relation.

```php
$qc = new Query\Connection();
$qc->set( 'from', $post_id_from );
$qc->set( 'to', $post_id_to );

$wpc_client->getRelation( 'post-to-page' )->createConnection( $qc );
```
