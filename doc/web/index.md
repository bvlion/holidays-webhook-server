# トップ

## get /

index

### request

内容 | 値 | 説明
:--|:--|:--

```
http://localhost:8000/
```

### response

現在時刻（DB 側とサーバー側）

内容 | 値 | 説明
:--|:--|:--
server | yyyy-MM-dd HH:mm:ss T | サーバー側の現在時刻
db | yyyy-MM-dd HH:mm:ss | DB 側の現在時刻

```
{
  "server":"2021-09-05 08:27:23 UTC",
  "db":"2021-09-05 17:27:23"
}
```