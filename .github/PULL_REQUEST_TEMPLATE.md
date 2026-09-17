## What does this change and why?

<!-- Link the related issue or task, e.g. "Closes #12" or "T031". -->

## How was it tested?

<!-- Commands run, environments used (PHP / WordPress / web server), manual steps. -->

## Checklist

- [ ] `composer lint`, `composer analyse`, `composer test:unit` and `npm run test:integration` pass
- [ ] Works on PHP 7.4
- [ ] New endpoints check capabilities and nonces, with tests
- [ ] Output escaped, strings translatable (`wp-checkpoint`)
- [ ] Behaviour or archive format changes are described above
- [ ] All commits are signed off (`git commit -s`)
