# Graph Report - Services  (2026-10-12)

## Corpus Check
- Corpus is ~44,385 words - fits in a single context window. You may not need a graph.

## Summary
- 796 nodes · 1799 edges · 34 communities (10 shown, 24 thin omitted)
- Extraction: 100% EXTRACTED · 0% INFERRED · 0% AMBIGUOUS · INFERRED: 7 edges (avg confidence: 0.85)
- Token cost: 0 input · 0 output

## Community Hubs (Navigation)
- Community 0
- Community 1
- Community 2
- Community 3
- Community 4
- Community 5
- Community 6
- Community 7
- Community 8
- Community 9
- Community 10
- Community 11
- Community 12
- Community 13
- Community 14
- Community 15
- Community 16
- Community 17
- Community 18
- Community 20
- Community 21
- Community 22
- Community 23
- Community 24
- Community 25
- Community 26
- Community 27
- Community 28
- Community 29
- Community 30
- Community 31
- Community 32
- Community 33

## God Nodes (most connected - your core abstractions)
1. `ProcessDocumentService` - 33 edges
2. `InvestmentService` - 32 edges
3. `AssetMatchingService` - 26 edges
4. `CsvParserService` - 24 edges
5. `AdvancedReconcileService` - 22 edges
6. `GoogleDriveService` - 22 edges
7. `GenericApiProvider` - 21 edges
8. `AiUserSettingsResolver` - 20 edges
9. `AiPromptBuilder` - 16 edges
10. `AiStepGateway` - 16 edges

## Surprising Connections (you probably didn't know these)
- `ImportDuplicateDetectionService` --references--> `DuplicateDetectionService`  [EXTRACTED]
  Import/ImportDuplicateDetectionService.php → DuplicateDetectionService.php
- `WebScrapingProvider` --references--> `ScraperService`  [EXTRACTED]
  InvestmentPriceProviders/WebScrapingProvider.php → ScraperService.php
- `{closure#1}()` --calls--> `TransactionItemMergeService`  [INFERRED]
  TransactionService.php → TransactionItemMergeService.php
- `AdvancedReconcileService` --references--> `InvestmentService`  [EXTRACTED]
  AdvancedReconcileService.php → InvestmentService.php
- `ProcessDocumentService` --references--> `AiExtractionSchemaValidator`  [EXTRACTED]
  ProcessDocumentService.php → AiExtractionSchemaValidator.php

## Import Cycles
- None detected.

## Communities (34 total, 24 thin omitted)

### Community 0 - "Community 0"
Cohesion: 0.00
Nodes (11): AiUserSettingsResolver, ApiTokenService, CurrencyRateService, DuplicateDetectionService, {closure#1}(), {closure#3}(), {closure#4}(), {closure#7}() (+3 more)

### Community 1 - "Community 1"
Cohesion: 0.00
Nodes (4): AiStepGateway, OcrService, ProcessDocumentService, TextExtractionService

### Community 2 - "Community 2"
Cohesion: 0.00
Nodes (9): AssetOverviewService, {closure#2}(), {closure#3}(), {closure#4}(), {closure#5}(), BudgetService, {closure#1}(), InflationCalculator (+1 more)

### Community 4 - "Community 4"
Cohesion: 0.00
Nodes (5): AccountGroupService, ImagePreprocessingService, InvestmentGroupService, PdfExtractionService, ProcessingHistoryRecorder

### Community 5 - "Community 5"
Cohesion: 0.00
Nodes (10): AdvancedReconcileService, {closure#1}(), {closure#10}(), {closure#11}(), {closure#2}(), {closure#4}(), {closure#6}(), {closure#8}() (+2 more)

### Community 6 - "Community 6"
Cohesion: 0.00
Nodes (9): {closure#10}(), {closure#11}(), {closure#12}(), {closure#13}(), {closure#8}(), CategoryService, {closure#2}(), {closure#5}() (+1 more)

### Community 8 - "Community 8"
Cohesion: 0.00
Nodes (3): AiExtractionSchemaValidator, CategoryLearningManagementService, CategoryLearningService

### Community 10 - "Community 10"
Cohesion: 0.00
Nodes (3): {closure#2}(), {closure#4}(), ImportNormalizationService

### Community 12 - "Community 12"
Cohesion: 0.00
Nodes (8): {closure#10}(), {closure#14}(), {closure#16}(), {closure#17}(), {closure#2}(), {closure#5}(), {closure#7}(), {closure#9}()

### Community 24 - "Community 24"
Cohesion: 0.00
Nodes (7): {closure#12}(), {closure#15}(), {closure#8}(), {closure#1}(), {closure#3}(), {closure#4}(), {closure#5}()

## Knowledge Gaps
- **24 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.