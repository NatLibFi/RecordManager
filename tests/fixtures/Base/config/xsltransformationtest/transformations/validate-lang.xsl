<xsl:stylesheet version="1.0" xmlns:xsl="http://www.w3.org/1999/XSL/Transform">
  <xsl:output method="xml" indent="no"/>

  <xsl:template name="validatelang">
    <xsl:param name="lang"/>

    <xsl:variable name="chars" select="'abcdefghijklmnopqrstuvwxyz'"/>
    <xsl:choose>

      <xsl:when test="(string-length($lang) = 2) and (contains($chars, substring($lang,1,1))) and (contains($chars, substring($lang,2,1)))">
        <xsl:value-of select="$lang"/>
      </xsl:when>

      <xsl:when test="(string-length($lang) = 3) and (contains($chars, substring($lang,1,1))) and (contains($chars, substring($lang,2,1))) and (contains($chars, substring($lang,3,1)))">
        <xsl:value-of select="$lang"/>
      </xsl:when>
 
      <xsl:otherwise>
        <xsl:value-of select="'fi'"/>
      </xsl:otherwise>

    </xsl:choose>

  </xsl:template>

</xsl:stylesheet>
