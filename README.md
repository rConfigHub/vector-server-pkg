# Corrected Tagging and Version Management Workflow

## Pre-requisites
- Determine the next version number (e.g., v1.0.16)
- Ensure all changes are ready for release

## Step-by-Step Process

### 1. Create and Switch to New Branch
```bash
 cd ../vector-server-pkg/
git checkout -b release/v1.1.1
```

### 2. Make Your Changes
- Implement all necessary code changes
- Test thoroughly

### 3. Update Composer Version
- Open `composer.json` in the `rconfig/vector-server` repository
- Update the `version` field:
```json
"version": "v1.1.1"
```
**Note:** Consider removing the version field entirely and let Composer infer from Git tags

### 4. Commit Changes
```bash
git add .
git commit -m "Prepare release v1.1.1"
```

### 5. Merge to Main Branch
```bash
git checkout main
git merge release/v1.0.19
git push origin main
```

### 6. Create and Push Git Tag
```bash
git tag -a v1.0.18 -m "Release version v1.0.18"
git push origin v1.0.18
```

### 7. Clear Composer Cache and Update
```bash
composer clear-cache
composer update
```

### 8. Clear rConfig Cache
```bash
php artisan rconfig:clear-all
```

### 9. Create GitHub Release
- Go to GitHub repository
- Create new release using the v1.0.16 tag
- Add release notes describing changes

### 10. Update Package Repository
- Ensure package is updated in [Repman](https://app.repman.io/login)
- Verify Packagist update if applicable

## If You Need to Fix an Incorrect Tag

If you created the wrong tag or it points to the wrong commit:

```bash
# Delete incorrect tag locally and remotely
git tag -d v1.0.16
git push origin :refs/tags/v1.0.16

# Create correct tag on the right commit
git checkout [correct-commit-hash]
git tag -a v1.0.16 -m "Release version v1.0.16"
git push origin v1.0.16
```

## Best Practices

1. **Use semantic versioning** (MAJOR.MINOR.PATCH)
2. **Test before tagging** - Run all tests in the rConfig V7 test suite
3. **Consistent naming** - Use the same version number throughout the process
4. **Clean git history** - Use meaningful commit messages
5. **Document changes** - Include clear release notes in GitHub releases