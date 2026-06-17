<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform" xmlns:php="http://php.net/xsl">
  <xsl:output method="xml" indent="no"/>
 
  <xsl:template name="europeanalic">
    <xsl:param name="license"/>

	<xsl:variable name="licenseLC" select="php:function('mb_strtolower', string($license), 'UTF-8')"/>

    <xsl:choose>
      <xsl:when test="contains($licenseLC, 'cc') and (contains($licenseLC, '4.0') or contains($licenseLC, '3.0'))">
	      <!-- CC license -->
        <xsl:value-of select="'http://creativecommons.org/licenses/'"/>
        <xsl:variable name="ccver" select="substring($licenseLC, string-length($licenseLC)-2)"/>
        <xsl:variable name="cclic" select="normalize-space(substring-before(substring-after($licenseLC, 'cc '), $ccver))"/>
        <xsl:value-of select="$cclic"/>
        <xsl:value-of select="'/'"/>
        <xsl:value-of select="$ccver"/>
        <xsl:value-of select="'/'"/>
      </xsl:when>

      <xsl:when test="contains($licenseLC, 'public domain') or contains($license, 'publicdomain')">
        <!-- Public Domain -->
        <xsl:value-of select="'http://creativecommons.org/publicdomain/mark/1.0/'"/>
      </xsl:when>

      <xsl:when test="contains($licenseLC, 'CC0')">
        <!-- CC0 -->
        <xsl:value-of select="'http://creativecommons.org/publicdomain/zero/1.0/'"/>
      </xsl:when>

      <xsl:when test="contains($licenseLC, 'inc') or contains($license, 'copyright')">
        <!-- In Copyright -->
        <xsl:value-of select="'http://rightsstatements.org/vocab/InC/1.0/'"/>
      </xsl:when>

      <xsl:when test="contains($licenseLC, 'cne')">
        <!-- Copyright Not Evaluated -->
        <xsl:value-of select="'hhttp://rightsstatements.org/vocab/CNE/1.0/'"/>
      </xsl:when>

      <xsl:otherwise>
        <xsl:value-of select="$license"/>
      </xsl:otherwise>

    </xsl:choose>
  </xsl:template>

</xsl:stylesheet>
