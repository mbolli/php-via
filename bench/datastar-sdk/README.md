# Datastar PHP SDK event formatting

Benchmark and flame graphs for a proposed change to `EventTrait::getMultiDataLines()` in
[starfederation/datastar-php](https://github.com/starfederation/datastar-php): one `str_replace()`
per payload instead of a `getDataLine()` call per line. php-via does the same in
`src/Http/SwooleSSEGenerator.php` since 0.14.2.

## Run it

```bash
git clone --depth 1 -b 1.0.1 https://github.com/starfederation/datastar-php sdk-stock
cp -r sdk-stock sdk-patched
patch -d sdk-patched -p0 < patch-getMultiDataLines.diff

php bench.php sdk=sdk-stock   mode=time size=300
php bench.php sdk=sdk-patched mode=time size=300   # also checks all 115 outputs are byte-identical
```

`size` is 30, 300 or 2000 lines of HTML. `mode=profile` writes Excimer folded stacks.

## Files

- `before.png`, `after.png`, `diff.png`: flame graphs of the 300-line case, stock and patched SDK,
  and the difference (blue is less CPU after).
- `chat-before.png`, `chat-after.png`: php-via's Chat Room with 300 tabs, with and without the
  change only.
