# MySQL AES extension for Laravel 5

## Installation

```
composer config repositories.aes-mysql vcs https://github.com/giovdi/laravel-mysql-aes
composer require giovdi/laravel-mysql-aes
```

## Usage

In your models change

```
use Illuminate\Database\Eloquent\Model;
```

with

```
use Pixelstyle\AesMysql\Model;
```

and add a new protected variable `$crypted` to define columns that will be crypted.

Full example:

```
namespace App;

use Pixelstyle\AesMysql\Model;

class Test extends Model
{
	protected $table = 'mytable';
	protected $primaryKey = 'id';
	protected $fillable = ['column1', 'column2', 'column3'];
	protected $crypted = ['column1', 'column2];
}

```

## Update
As in alpha, we strongly suggest to remove and install the library to update it:

```
composer remove giovdi/laravel-mysql-aes
composer require giovdi/laravel-mysql-aes
```