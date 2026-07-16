<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

requireLogin();
requireRole('vip');

$network = sanitize($_GET['network'] ?? 'at');

// Create a proper Excel file using simple XML format
$excel_content = '<?xml version="1.0"?>
<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:o="urn:schemas-microsoft-com:office:office"
 xmlns:x="urn:schemas-microsoft-com:office:excel"
 xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"
 xmlns:html="http://www.w3.org/TR/REC-html40">
 <DocumentProperties xmlns="urn:schemas-microsoft-com:office:office">
  <Title>' . strtoupper($network) . ' Bulk Upload Template</Title>
 </DocumentProperties>
 <Worksheet ss:Name="Sheet1">
  <Table>
   <Row>
    <Cell><Data ss:Type="String">NUMBER</Data></Cell>
    <Cell><Data ss:Type="String">VOLUME</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">0245152060</Data></Cell>
    <Cell><Data ss:Type="String">1GB</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">0201234567</Data></Cell>
    <Cell><Data ss:Type="String">2GB</Data></Cell>
   </Row>
   <Row>
    <Cell><Data ss:Type="String">0501234567</Data></Cell>
    <Cell><Data ss:Type="String">5GB</Data></Cell>
   </Row>
  </Table>
 </Worksheet>
</Workbook>';

// Set headers for Excel download
header('Content-Type: application/vnd.ms-excel');
header('Content-Disposition: attachment; filename="' . strtoupper($network) . '_bulk_template.xls"');
header('Cache-Control: max-age=0');

// Output Excel content
echo $excel_content;
exit;
